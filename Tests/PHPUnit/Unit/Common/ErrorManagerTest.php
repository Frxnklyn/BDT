<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\ErrorManager;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5AbstractNode;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Events\AfterSubstep;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests error formatting and log-reference handling.
 *
 * Stub loggers and mocked browsers keep these tests unit-scoped: a real workbench would
 * initialize application services and risk database or monitor writes. The doubles let
 * failures be injected without launching Chrome or depending on external infrastructure.
 */
class ErrorManagerTest extends TestCase
{
    /**
     * Isolates singleton state so one test's errors and IDs cannot make another test pass.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $instance = new ReflectionProperty(ErrorManager::class, 'instance');
        $instance->setValue(null, null);
    }

    /**
     * Keeps the same singleton isolation for tests outside this class.
     */
    protected function tearDown(): void
    {
        $instance = new ReflectionProperty(ErrorManager::class, 'instance');
        $instance->setValue(null, null);
        parent::tearDown();
    }

    /**
     * Guards both formatter paths against replacing context or inventing a missing log reference.
     */
    #[DataProvider('formattedErrorProvider')]
    public function testFormattersPreserveContextAndOptionalLogId(string $type, ?string $logId): void
    {
        $manager = ErrorManager::getInstance();
        $error = [
            'type' => $type,
            'message' => 'Original failure',
            'status' => 500,
            'stack' => 'original trace',
        ];
        if ($logId !== null) {
            $error['logId'] = $logId;
        }

        $formatted = $manager->formatErrorMessage($error);

        self::assertStringContainsString("Source: Unknown\n", $formatted);
        self::assertStringContainsString("Message: Original failure\n", $formatted);
        self::assertStringContainsString("Type: " . $type . "\nStatus: 500\n", $formatted);
        if ($logId !== null && $logId !== '') {
            self::assertStringContainsString("Log ID: " . $logId . "\n", $formatted);
            self::assertSame(1, substr_count($formatted, 'Log ID: '));
        } else {
            self::assertStringNotContainsString('Log ID:', $formatted);
        }
        if ($type === 'BehatException') {
            self::assertSame($formatted, $manager->formatBehatExceptionMessage($error));
            self::assertStringContainsString("Stack Trace:\noriginal trace\n", $formatted);
        }
    }

    /**
     * Covers both display paths with a real, absent or empty ID, independently of source presence.
     *
     * @return iterable<string,array{string,?string}>
     */
    public static function formattedErrorProvider(): iterable
    {
        foreach (['Exception', 'BehatException'] as $type) {
            yield $type . ' with ID' => [$type, 'original-id'];
            yield $type . ' without ID' => [$type, null];
            yield $type . ' with empty ID' => [$type, ''];
        }
    }

    /**
     * Ensures the AfterStep collection boundary does not discard the ID before formatting.
     */
    public function testCollectedBehatErrorKeepsItsLogId(): void
    {
        $manager = ErrorManager::getInstance();
        $manager->addError([
            'type' => 'BehatException',
            'message' => 'Original failure',
            'logId' => 'original-id',
        ], 'AfterStep');

        $error = $manager->getFirstError();
        self::assertNotNull($error);
        self::assertStringContainsString(
            "Source: AfterStep\nLog ID: original-id\nMessage: Original failure\n",
            $manager->formatErrorMessage($error)
        );
    }

    /**
     * Prevents multiline messages from breaking markdown while keeping log references optional.
     */
    public function testBuildErrorLineKeepsMessagesOnOneLine(): void
    {
        self::assertSame(
            '**ERROR:** First second third fourth (Log-ID: original-id)',
            ErrorManager::buildErrorLine(" First\r\nsecond\nthird\u{2028}fourth ", 'original-id')
        );
        self::assertSame('**ERROR:** Failure', ErrorManager::buildErrorLine('Failure'));
        self::assertSame('**ERROR:** Failure', ErrorManager::buildErrorLine('Failure', ''));
    }

    /**
     * Verifies that a real logger call precedes exposing the ID to step persistence.
     */
    public function testLogExceptionReturnsAndStoresLoggedId(): void
    {
        $manager = ErrorManager::getInstance();
        $manager->setLastLogId('previous-id');
        $exception = new RuntimeException('Original failure');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('logException')
            ->with(self::isInstanceOf(RuntimeException::class));

        $id = $manager->logException($exception, $this->workbench($logger));

        self::assertNotEmpty($id);
        self::assertSame($exception->getId(), $id);
        self::assertSame($id, $manager->getLastLogId());
    }

    /**
     * Prevents a substep rethrown into the AfterStep hook from generating another monitor entry.
     */
    public function testSameExceptionIsLoggedOnlyOnceAndRestoresItsId(): void
    {
        $manager = ErrorManager::getInstance();
        $exception = new RuntimeException('Original failure');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('logException');
        $workbench = $this->workbench($logger);

        $id = $manager->logException($exception, $workbench);
        $manager->setLastLogId('secondary-id');

        self::assertNotEmpty($id);
        self::assertSame($id, $manager->logException($exception, $workbench));
        self::assertSame($id, $manager->getLastLogId());
    }

    /**
     * Keeps independent exception objects distinguishable even when their messages are identical.
     */
    public function testDifferentExceptionsAreLoggedWithDifferentIds(): void
    {
        $manager = ErrorManager::getInstance();
        $first = new RuntimeException('Failure');
        $second = new RuntimeException('Failure');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('logException');
        $workbench = $this->workbench($logger);

        $firstId = $manager->logException($first, $workbench);
        $secondId = $manager->logException($second, $workbench);

        self::assertNotEmpty($firstId);
        self::assertNotEmpty($secondId);
        self::assertNotSame($firstId, $secondId);
        self::assertSame($secondId, $manager->getLastLogId());
    }

    /**
     * Protects local-only detector and destructor callers from changing the central log reference.
     */
    public function testLogExceptionWithoutWorkbenchKeepsPreviousId(): void
    {
        $manager = ErrorManager::getInstance();
        $manager->setLastLogId('previous-id');

        self::assertNull($manager->logException(new RuntimeException('Local failure')));
        self::assertSame('previous-id', $manager->getLastLogId());
    }

    /**
     * Prevents a failed monitor write from advertising a new ID or swallowing the logger failure.
     */
    public function testFailedLoggerWriteKeepsPreviousIdAndPropagates(): void
    {
        $manager = ErrorManager::getInstance();
        $manager->setLastLogId('previous-id');
        $loggingError = new RuntimeException('Monitor unavailable');
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('logException')->willThrowException($loggingError);

        try {
            $manager->logException(new RuntimeException('Original failure'), $this->workbench($logger));
            self::fail('The logger failure must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame($loggingError, $e);
            self::assertSame('previous-id', $manager->getLastLogId());
        }
    }

    /**
     * Keeps a swallowed screenshot failure from changing the ID observed by substep persistence.
     */
    #[DataProvider('screenshotFailureProvider')]
    public function testSubstepScreenshotFailureKeepsOriginalLogId(bool $callbackChangesId): void
    {
        $manager = ErrorManager::getInstance();
        $original = new RuntimeException('Original failure');
        $screenshotError = new RuntimeException('Screenshot failed');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('logException');
        $logger->expects(self::once())->method('warning')->with(
            'BDT: Could not capture screenshot: Screenshot failed',
            ['exception' => $screenshotError]
        );
        $dispatcher = new EventDispatcher();
        $eventLogId = 'event-not-dispatched';
        $dispatcher->addListener(AfterSubstep::class, static function () use (&$eventLogId): void {
            $eventLogId = ErrorManager::getInstance()->getLastLogId();
        });
        $browser = $this->getMockBuilder(UI5Browser::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorkbench', 'getEventDispatcher'])
            ->getMock();
        $browser->expects($this->atLeastOnce())->method('getWorkbench')->willReturn($this->workbench($logger));
        $browser->method('getEventDispatcher')->willReturn($dispatcher);
        $browser->setScreenshotFn(static fn () => throw $screenshotError);
        $node = $this->getMockBuilder(UI5AbstractNode::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBrowser', 'getWidgetType'])
            ->getMock();
        $node->expects($this->atLeastOnce())->method('getBrowser')->willReturn($browser);
        $node->method('getWidgetType')->willReturn('TestWidget');

        $result = $node->runAsSubstep(
            static fn () => throw $original,
            'Failing substep',
            null,
            null,
            $callbackChangesId ? static fn () => $manager->setLastLogId('cleanup-id') : null
        );

        self::assertTrue($result->isFailed());
        self::assertSame($original, $result->getException());
        self::assertSame($original->getId(), $eventLogId);
        self::assertSame($original->getId(), $manager->getLastLogId());
    }

    /**
     * Covers both screenshot-only failures and later callbacks that silently overwrite global ID state.
     *
     * @return iterable<string,array{bool}>
     */
    public static function screenshotFailureProvider(): iterable
    {
        yield 'screenshot failure' => [false];
        yield 'screenshot failure followed by callback logging' => [true];
    }

    /**
     * Supplies an isolated logger without starting a workbench or contacting the monitor database.
     *
     * @param LoggerInterface $logger Logger double used by the current test.
     * @return WorkbenchInterface A workbench exposing only that logger.
     */
    private function workbench(LoggerInterface $logger): WorkbenchInterface
    {
        $workbench = $this->createStub(WorkbenchInterface::class);
        $workbench->method('getLogger')->willReturn($logger);
        return $workbench;
    }
}

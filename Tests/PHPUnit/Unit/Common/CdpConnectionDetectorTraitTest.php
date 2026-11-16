<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\Traits\CdpConnectionDetectorTrait;
use axenox\BDT\Exceptions\ChromeHangException;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use WebSocket\Exception\ConnectionClosedException;

/**
 * Prevents expected UI text from restarting Chrome without hiding real transport causes.
 */
class CdpConnectionDetectorTraitTest extends TestCase
{
    /**
     * Pins both entry points because retries and hook recovery must share assertion precedence.
     */
    #[DataProvider('cdpCauseProvider')]
    public function testCdpCausePrecedence(Throwable $exception, bool $connectionError, bool $restart): void
    {
        $detector = $this->createDetector();

        self::assertSame($connectionError, $detector->detectsConnectionError($exception));
        self::assertSame($restart, $detector->needsChromeRestart($exception));
    }

    /**
     * Prevents an underlying Chrome hang from enabling connection retries instead of recovery.
     */
    public function testChromeHangBelowAssertionOnlyRequestsRecovery(): void
    {
        $detector = $this->createDetector();
        $exception = new AssertionFailedError('Browser check failed', 0, new ChromeHangException('Chrome stalled'));

        self::assertFalse($detector->detectsConnectionError($exception));
        self::assertTrue($detector->needsChromeRestart($exception));
    }

    /**
     * Covers discarded wrappers, deeper assertion boundaries and unchanged transport detection.
     *
     * @return iterable<string,array{Throwable,bool,bool}>
     */
    public static function cdpCauseProvider(): iterable
    {
        yield 'assertion alone' => [
            new AssertionFailedError('Expected WebSocket label'),
            false,
            false
        ];
        yield 'wrapper copies assertion transport keyword' => [
            new RuntimeException('Expected WebSocket label', null, new AssertionFailedError('Expected WebSocket label')),
            false,
            false
        ];
        yield 'outer Chrome hang cannot override assertion' => [
            new ChromeHangException('Chrome stalled', null, new AssertionFailedError('Missing label')),
            false,
            false
        ];
        yield 'assertion with typed WebSocket cause' => [
            new AssertionFailedError('Browser check failed', 0, new ConnectionClosedException()),
            true,
            true
        ];
        yield 'wrapped assertion with transport message cause' => [
            new RuntimeException(
                'Browser check failed',
                null,
                new AssertionFailedError('Missing label', 0, new RuntimeException('Connection refused'))
            ),
            true,
            true
        ];
        yield 'deepest assertion discards intermediate transport cause' => [
            new AssertionFailedError(
                'Outer expectation',
                0,
                new ChromeHangException('WebSocket interrupted', null, new AssertionFailedError('Inner expectation'))
            ),
            false,
            false
        ];
        yield 'transport below deepest assertion is retained' => [
            new AssertionFailedError(
                'Outer expectation',
                0,
                new AssertionFailedError('Inner expectation', 0, new ConnectionClosedException())
            ),
            true,
            true
        ];
        yield 'no assertion and no transport failure' => [new RuntimeException('Missing widget'), false, false];
        yield 'no assertion and typed WebSocket failure' => [new ConnectionClosedException(), true, true];
        yield 'no assertion and wrapped typed WebSocket failure' => [
            new RuntimeException('Browser operation failed', null, new ConnectionClosedException()),
            true,
            true
        ];
        yield 'no assertion and top-level Chrome hang remains recovery-only' => [
            new ChromeHangException('Chrome stalled'),
            false,
            true
        ];
        yield 'no assertion and wrapped Chrome hang requests recovery only' => [
            new RuntimeException('Browser operation failed', null, new ChromeHangException('Chrome stalled')),
            false,
            true
        ];

        foreach ([
            'WebSocket',
            'Connection refused',
            'actively refused it',
            'No connection could be',
            'Unable to connect to tcp',
            'Could not connect',
            'Server is closed',
            'curl error',
            'Empty read',
            'connection dead',
            'version information'
        ] as $keyword) {
            yield 'no assertion and wrapped transport keyword: ' . $keyword => [
                new RuntimeException('Browser operation failed', null, new RuntimeException($keyword)),
                true,
                true
            ];
        }
    }

    /**
     * Keeps both tests on the same trait adapter without duplicating detector setup.
     *
     * @return object An adapter exposing both private detector entry points.
     */
    private function createDetector(): object
    {
        return new class {
            use CdpConnectionDetectorTrait {
                isCdpConnectionError as public detectsConnectionError;
                requiresChromeRestart as public needsChromeRestart;
            }
        };
    }
}

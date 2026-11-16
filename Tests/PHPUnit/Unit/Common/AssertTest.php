<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use ArrayIterator;
use ArrayObject;
use axenox\BDT\Behat\Common\Assert as BdtAssert;
use axenox\BDT\Exceptions\AssertionFailedException;
use Closure;
use Composer\Autoload\ClassLoader;
use EmptyIterator;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use Generator;
use IteratorIterator;
use PHPUnit\Framework\Assert as PhpUnitAssert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Stringable;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Pins runtime assertion outcomes to PHPUnit while proving failures do not need its runner.
 */
class AssertTest extends TestCase
{
    /**
     * Uses fresh arguments for each facade so stateful inputs cannot bias parity results.
     */
    #[DataProvider('parityProvider')]
    public function testParity(string $method, Closure $arguments, bool $passes): void
    {
        self::assertSame($passes, $this->passes(PhpUnitAssert::class, $method, $arguments()));
        self::assertSame($passes, $this->passes(BdtAssert::class, $method, $arguments()));
    }

    /**
     * Covers each public facade method, strict booleans and value-shape boundary cases.
     *
     * @return iterable<string,array{string,Closure,bool}>
     */
    public static function parityProvider(): iterable
    {
        $cases = [
            'assertNotNull' => [[[0], true], [[null], false]],
            'assertNull' => [[[null], true], [[false], false]],
            'assertTrue' => [[[true], true], [[1], false], [['1'], false]],
            'assertFalse' => [[[false], true], [[0], false], [[''], false]],
            'assertSame' => [[[0, 0], true], [[0, '0'], false], [['01', '1'], false]],
            'assertEquals' => [
                [[0, '0'], true],
                [['01', '1'], false],
                [[['nested' => ['value' => 0]], ['nested' => ['value' => '0']]], true],
                [[['nested' => ['value' => '01']], ['nested' => ['value' => '1']]], false]
            ],
            'assertNotEquals' => [[[0, '0'], false], [['01', '1'], true]],
            'assertEmpty' => [[[[]], true], [[new ArrayObject()], true], [[new EmptyIterator()], true], [[[1]], false]],
            'assertNotEmpty' => [[[[1]], true], [[new ArrayObject()], false], [[new EmptyIterator()], false]],
            'assertCount' => [[[2, [1, 2]], true], [[1, [1, 2]], false], [[0, new ArrayObject()], true]],
            'assertContains' => [[[0, [0]], true], [[0, ['0']], false]],
            'assertNotContains' => [[[0, ['0']], true], [[0, [0]], false]],
            'assertArrayHasKey' => [[['value', ['value' => null]], true], [['missing', []], false]],
            'assertInstanceOf' => [[[stdClass::class, new stdClass()], true], [[stdClass::class, null], false]],
            'assertNotInstanceOf' => [[[stdClass::class, null], true], [[stdClass::class, new stdClass()], false]],
            'assertIsArray' => [[[[]], true], [[new stdClass()], false]],
            'assertGreaterThan' => [[[0, 1], true], [[1, 1], false]],
            'fail' => [[['Verbatim failure'], false]]
        ];

        foreach ($cases as $method => $rows) {
            foreach ($rows as $index => [$arguments, $passes]) {
                yield $method . ' case ' . $index => [$method, static fn(): array => $arguments, $passes];
            }
        }

        $stringable = new class implements Stringable {
            /**
             * Models a rendered label that PHPUnit compares to a scalar string.
             *
             * @return string The visible label.
             */
            public function __toString(): string
            {
                return '1';
            }
        };
        yield 'Stringable numeric equality' => ['assertEquals', static fn(): array => ['01', $stringable], true];
        yield 'Stringable unequal label' => ['assertEquals', static fn(): array => ['other', $stringable], false];
        yield 'Stringable inequality' => ['assertNotEquals', static fn(): array => ['other', $stringable], true];
        yield 'known interface' => ['assertInstanceOf', static fn(): array => [Stringable::class, $stringable], true];
        yield 'forbidden interface' => ['assertNotInstanceOf', static fn(): array => [Stringable::class, $stringable], false];
        yield 'unknown class' => ['assertInstanceOf', static fn(): array => [__NAMESPACE__ . '\\MissingType', null], false];
        yield 'unknown forbidden class' => ['assertNotInstanceOf', static fn(): array => [__NAMESPACE__ . '\\MissingType', null], false];
        yield 'empty iterator count' => ['assertCount', static fn(): array => [0, new EmptyIterator()], true];
        yield 'iterable count' => ['assertCount', static fn(): array => [2, new ArrayIterator([1, 2])], true];
        yield 'non-countable iterable count' => [
            'assertCount',
            static fn(): array => [2, new IteratorIterator(new ArrayIterator([1, 2]))],
            true
        ];
        yield 'one-shot generator is rejected' => ['assertCount', self::generatorArguments(...), false];
    }

    /**
     * Prevents counting a reusable non-Countable iterator from consuming later checks' input.
     */
    public function testCountPreservesIteratorPosition(): void
    {
        $iterator = new IteratorIterator(new ArrayIterator(['first' => 1, 'second' => 2]));
        $iterator->rewind();
        $iterator->next();

        BdtAssert::assertCount(2, $iterator);

        self::assertSame('second', $iterator->key());
    }

    /**
     * Keeps the original exception reachable after a step turns its result into an assertion.
     */
    public function testFailPreservesMessageAndPrevious(): void
    {
        $previous = new RuntimeException('Connection refused');
        $message = "Original assertion message\nwith detail";
        try {
            BdtAssert::fail($message, $previous);
        } catch (AssertionFailedError $exception) {
            self::assertInstanceOf(AssertionFailedException::class, $exception);
            self::assertSame($message, $exception->getMessage());
            self::assertSame($previous, $exception->getPrevious());
            return;
        }
        self::fail('The runtime assertion did not throw.');
    }

    /**
     * Checks invalid type names separately so parity cannot hide a false-green negative check.
     */
    #[DataProvider('unknownTypeProvider')]
    public function testUnknownTypesThrowFrameworkInvalidArgument(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        BdtAssert::$method(__NAMESPACE__ . '\\MissingType', null);
    }

    /**
     * Covers both type-check directions because only the negative one can silently pass a typo.
     *
     * @return array<string,array{string}>
     */
    public static function unknownTypeProvider(): array
    {
        return [
            'positive' => ['assertInstanceOf'],
            'negative' => ['assertNotInstanceOf']
        ];
    }

    /**
     * Runs outside PHPUnit so a pre-initialized Registry cannot make this regression test trivial.
     */
    public function testFailureMessageDoesNotRequireRunnerRegistry(): void
    {
        $loaderFile = (new ReflectionClass(ClassLoader::class))->getFileName();
        self::assertIsString($loaderFile);
        $autoload = dirname($loaderFile, 2) . DIRECTORY_SEPARATOR . 'autoload.php';
        $script = <<<'PHP'
require $argv[1];
try {
    \axenox\BDT\Behat\Common\Assert::assertNotNull(null, 'Column "Missing" not found');
} catch (\PHPUnit\Framework\AssertionFailedError $exception) {
    echo $exception->getMessage();
    exit(0);
}
exit(1);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'zend.assertions=1', '-d', 'assert.exception=1', '-r', $script, $autoload]);
        $process->setTimeout(10);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('Column "Missing" not found', $process->getOutput());
    }

    /**
     * Compares acceptance, not exception classes: invalid inputs are rejected by either facade.
     *
     * This catch is the parity-test boundary; unexpected exceptions still fail passing cases.
     *
     * @param class-string $class The assertion implementation to exercise.
     * @param string $method The assertion method shared by both implementations.
     * @param array<mixed> $arguments Fresh arguments for this invocation.
     * @return bool True only when the assertion returns normally.
     */
    private function passes(string $class, string $method, array $arguments): bool
    {
        try {
            $class::$method(...$arguments);
            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Allocates a generator per implementation so neither receives an already-consumed input.
     *
     * @return array{int,Generator} The expected count and one-shot iterable.
     */
    private static function generatorArguments(): array
    {
        return [1, self::oneShotValues()];
    }

    /**
     * Supplies a genuine one-shot iterable rather than a Countable iterator substitute.
     *
     * @return Generator<int,int> The single yielded value.
     */
    private static function oneShotValues(): Generator
    {
        yield 1;
    }
}

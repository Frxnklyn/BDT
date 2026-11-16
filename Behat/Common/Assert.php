<?php
namespace axenox\BDT\Behat\Common;

use ArrayAccess;
use Countable;
use EmptyIterator;
use Generator;
use Iterator;
use IteratorAggregate;
use SplObjectStorage;
use Stringable;
use Throwable;
use axenox\BDT\Exceptions\AssertionFailedException;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Preserves useful assertion failures when Behat runs without the PHPUnit runner.
 *
 * Since PHPUnit 11.3, Assert failure descriptions can reach PHPUnit's Exporter,
 * which reads TextUI Configuration Registry. Behat never initializes that registry,
 * so a native AssertionError (or TypeError with assertions disabled) replaces the
 * real failure. BDT formats its own messages without exporters or runner state,
 * while retaining the AssertionFailedError hierarchy for existing catches.
 *
 * Methods are added on demand only and must mirror PHPUnit semantics.
 * fail() additionally accepts an optional previous throwable to preserve causes.
 *
 * @author Copilot
 */
final class Assert
{
    /**
     * Keeps missing model elements distinguishable from infrastructure failures.
     *
     * @param mixed $actual The value expected not to be null.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is null.
     */
    public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        if ($actual === null) {
            self::fail($message !== '' ? $message : 'Expected a non-null value.');
        }
    }

    /**
     * Lets negative lookup expectations fail without consulting runner state.
     *
     * @param mixed $actual The value expected to be null.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is not null.
     */
    public static function assertNull(mixed $actual, string $message = ''): void
    {
        if ($actual !== null) {
            self::fail($message !== '' ? $message : 'Expected null, got ' . self::describeValue($actual) . '.');
        }
    }

    /**
     * Prevents truthy non-booleans from satisfying a positive expectation.
     *
     * @param mixed $condition The value expected to be exactly true.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is not true.
     */
    public static function assertTrue(mixed $condition, string $message = ''): void
    {
        if ($condition !== true) {
            self::fail($message !== '' ? $message : 'Expected true, got ' . self::describeValue($condition) . '.');
        }
    }

    /**
     * Prevents falsy non-booleans from satisfying a negative expectation.
     *
     * @param mixed $condition The value expected to be exactly false.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is not false.
     */
    public static function assertFalse(mixed $condition, string $message = ''): void
    {
        if ($condition !== false) {
            self::fail($message !== '' ? $message : 'Expected false, got ' . self::describeValue($condition) . '.');
        }
    }

    /**
     * Preserves type-sensitive comparisons of counts, captions and rendered values.
     *
     * @param mixed $expected The expected value and type.
     * @param mixed $actual The observed value.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the values are not identical.
     */
    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            self::fail($message !== '' ? $message : 'Expected ' . self::describeValue($expected)
                . ', got ' . self::describeValue($actual) . ' (strict comparison).');
        }
    }

    /**
     * Allows equivalent numeric values while keeping textual expectations exact.
     *
     * @param mixed $expected The expected value.
     * @param mixed $actual The observed value.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the values are not equal.
     */
    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if (!self::valuesEqual($expected, $actual)) {
            self::fail($message !== '' ? $message : 'Expected ' . self::describeValue($expected)
                . ', got ' . self::describeValue($actual) . '.');
        }
    }

    /**
     * Keeps rejected widget values on the same failure path as positive expectations.
     *
     * @param mixed $expected The value the actual value must differ from.
     * @param mixed $actual The observed value.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the values are equal.
     */
    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if (self::valuesEqual($expected, $actual)) {
            self::fail($message !== '' ? $message : 'Expected a value different from ' . self::describeValue($expected) . '.');
        }
    }

    /**
     * Prevents PHP object truthiness from hiding an empty Countable collection.
     *
     * @param mixed $actual The value expected to be empty.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is not empty.
     */
    public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        if (!self::isEmpty($actual)) {
            self::fail($message !== '' ? $message : 'Expected an empty value, got ' . self::describeValue($actual) . '.');
        }
    }

    /**
     * Makes empty widget collections fail even when represented by Countable objects.
     *
     * @param mixed $actual The value expected not to be empty.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is empty.
     */
    public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        if (self::isEmpty($actual)) {
            self::fail($message !== '' ? $message : 'Expected a non-empty value.');
        }
    }

    /**
     * Keeps collection-size failures independent of PHPUnit constraints.
     *
     * PHPUnit rejects one-shot generators rather than consuming them. Other iterators
     * are returned to their previous key so counting cannot change subsequent checks.
     * Repeated IteratorAggregate objects are rejected because they cannot resolve to
     * a countable iterator and would otherwise loop forever.
     * EmptyIterator is counted directly because key() throws instead of returning a position.
     *
     * @param int $expectedCount The expected collection size.
     * @param Countable|iterable<mixed> $haystack The collection to count.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the count differs.
     * @throws InvalidArgumentException If the collection is invalid, cyclic or a generator.
     * @throws Throwable If iteration fails.
     */
    public static function assertCount(int $expectedCount, mixed $haystack, string $message = ''): void
    {
        if (!is_countable($haystack) && !is_iterable($haystack)) {
            throw new InvalidArgumentException('assertCount() requires a Countable or iterable value.');
        }

        if (is_countable($haystack)) {
            $actualCount = count($haystack);
        } elseif ($haystack instanceof EmptyIterator) {
            $actualCount = 0;
        } else {
            $seen = new SplObjectStorage();
            while ($haystack instanceof IteratorAggregate) {
                if ($seen->contains($haystack)) {
                    throw new InvalidArgumentException('assertCount() cannot count a cyclic IteratorAggregate.');
                }
                $seen->attach($haystack);
                $haystack = $haystack->getIterator();
            }
            if ($haystack instanceof Generator) {
                throw new InvalidArgumentException('assertCount() does not support one-shot generators.');
            }

            $key = $haystack instanceof Iterator ? $haystack->key() : null;
            $actualCount = iterator_count($haystack);
            if ($haystack instanceof Iterator && $key !== null) {
                $haystack->rewind();
                while ($haystack->valid() && $haystack->key() !== $key) {
                    $haystack->next();
                }
            }
        }
        if ($expectedCount !== $actualCount) {
            self::fail($message !== '' ? $message : 'Expected count ' . $expectedCount . ', got ' . $actualCount . '.');
        }
    }

    /**
     * Prevents loose coercion from finding the wrong menu entry or caption.
     *
     * @param mixed $needle The value required in the collection.
     * @param iterable<mixed> $haystack The collection to inspect strictly.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is absent.
     */
    public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        if (!self::containsValue($needle, $haystack)) {
            self::fail($message !== '' ? $message : 'Expected collection to contain ' . self::describeValue($needle) . '.');
        }
    }

    /**
     * Keeps forbidden-entry checks as strict as positive membership checks.
     *
     * @param mixed $needle The value forbidden in the collection.
     * @param iterable<mixed> $haystack The collection to inspect strictly.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is present.
     */
    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        if (self::containsValue($needle, $haystack)) {
            self::fail($message !== '' ? $message : 'Expected collection not to contain ' . self::describeValue($needle) . '.');
        }
    }

    /**
     * Reports missing spreadsheet fields without confusing null values with absent keys.
     *
     * @param int|string $key The required key.
     * @param array<mixed>|ArrayAccess $array The indexed collection.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the key is absent.
     * @throws InvalidArgumentException If the key is not an integer or string.
     */
    public static function assertArrayHasKey(mixed $key, array|ArrayAccess $array, string $message = ''): void
    {
        if (!is_int($key) && !is_string($key)) {
            throw new InvalidArgumentException('assertArrayHasKey() requires an integer or string key.');
        }

        $exists = is_array($array) ? array_key_exists($key, $array) : $array->offsetExists($key);
        if (!$exists) {
            self::fail($message !== '' ? $message : 'Expected key ' . self::describeValue($key) . ' to exist.');
        }
    }

    /**
     * Keeps failed widget-type expectations out of undefined-method error paths.
     *
     * Unknown types are rejected explicitly so a misspelled class cannot obscure a
     * configuration error or be accepted by the corresponding negative assertion.
     *
     * @param class-string $expected The required class or interface name.
     * @param mixed $actual The observed value.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is not an instance of the type.
     * @throws InvalidArgumentException If the type does not exist.
     */
    public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::requireKnownType($expected);
        if (!$actual instanceof $expected) {
            self::fail($message !== '' ? $message : 'Expected an instance of ' . $expected
                . ', got ' . self::describeValue($actual) . '.');
        }
    }

    /**
     * Allows forbidden widget types to be rejected as ordinary expectation failures.
     *
     * @param class-string $expected The forbidden class or interface name.
     * @param mixed $actual The observed value.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is an instance of the type.
     * @throws InvalidArgumentException If the type does not exist.
     */
    public static function assertNotInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::requireKnownType($expected);
        if ($actual instanceof $expected) {
            self::fail($message !== '' ? $message : 'Expected no instance of ' . $expected . '.');
        }
    }

    /**
     * Preserves diagnostics when browser-side extraction returns the wrong shape.
     *
     * @param mixed $actual The value expected to be an array.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value is not an array.
     */
    public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        if (!is_array($actual)) {
            self::fail($message !== '' ? $message : 'Expected an array, got ' . self::describeValue($actual) . '.');
        }
    }

    /**
     * Keeps minimum-result expectations readable without a PHPUnit constraint.
     *
     * @param mixed $minimum The exclusive lower bound.
     * @param mixed $actual The observed value.
     * @param string $message Caller-supplied failure message.
     * @return void
     * @throws AssertionFailedException If the value does not exceed the bound.
     */
    public static function assertGreaterThan(mixed $minimum, mixed $actual, string $message = ''): void
    {
        if (!($actual > $minimum)) {
            self::fail($message !== '' ? $message : 'Expected ' . self::describeValue($actual)
                . ' to be greater than ' . self::describeValue($minimum) . '.');
        }
    }

    /**
     * Gives every runtime assertion a runner-independent, catch-compatible failure boundary.
     *
     * The previous throwable preserves transport causes hidden by an aggregate failure message.
     *
     * @param string $message Caller-supplied failure message.
     * @param Throwable|null $previous The original failure being converted into an assertion.
     * @return never
     * @throws AssertionFailedException Always, with the supplied cause attached.
     */
    public static function fail(string $message = '', ?Throwable $previous = null): never
    {
        throw new AssertionFailedException($message !== '' ? $message : 'Assertion failed.', 0, $previous);
    }

    /**
     * Shares strict membership semantics so positive and negative checks cannot drift.
     *
     * @param mixed $needle The value to find.
     * @param iterable<mixed> $haystack The collection to inspect.
     * @return bool True when an identical value is present.
     * @throws Throwable If iteration fails.
     */
    private static function containsValue(mixed $needle, iterable $haystack): bool
    {
        foreach ($haystack as $value) {
            if ($needle === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shares PHP emptiness plus collection-size semantics between opposite expectations.
     *
     * @param mixed $actual The value to inspect.
     * @return bool True when the value is empty.
     * @throws Throwable If Countable::count() fails.
     */
    private static function isEmpty(mixed $actual): bool
    {
        if ($actual instanceof EmptyIterator) {
            return true;
        }
        return $actual instanceof Countable ? count($actual) === 0 : empty($actual);
    }

    /**
     * Avoids loose numeric-string equality while retaining numeric input coercion.
     *
     * PHPUnit compares two strings exactly, including nested array values. Using
     * PHP's == alone would silently accept different captions such as "01" and "1".
     *
     * @param mixed $expected The expected value.
     * @param mixed $actual The observed value.
     * @return bool True when the values compare equally.
     * @throws Throwable If a Stringable conversion or recursive comparison fails.
     */
    private static function valuesEqual(mixed $expected, mixed $actual): bool
    {
        if (is_string($expected) && is_string($actual)) {
            return $expected === $actual;
        }
        if (is_array($expected) || is_array($actual)) {
            if (!is_array($expected) || !is_array($actual) || count($expected) !== count($actual)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual) || !self::valuesEqual($value, $actual[$key])) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($expected) && $actual instanceof Stringable) {
            return $expected == (string) $actual;
        }
        if ($expected instanceof Stringable && is_string($actual)) {
            return (string) $expected == $actual;
        }
        if (is_object($expected) || is_object($actual)) {
            return is_object($expected) && is_object($actual) && $expected == $actual;
        }
        return $expected == $actual;
    }

    /**
     * Bounds default diagnostics without traversing objects or invoking value exporters.
     *
     * @param mixed $value The value to describe.
     * @return string A bounded, runner-independent description.
     * @throws Throwable If string formatting fails.
     */
    private static function describeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_string($value)) {
            $short = StringDataType::truncate($value, 80, false, false, true);
            return '"' . addcslashes($short, "\0..\37\177\"\\") . '"';
        }
        return is_scalar($value) ? (string) $value : gettype($value);
    }

    /**
     * Shares type-name validation so negative assertions cannot turn typos into passing checks.
     *
     * @param string $expected The class or interface name supplied by the caller.
     * @return void
     * @throws InvalidArgumentException If the type does not exist.
     */
    private static function requireKnownType(string $expected): void
    {
        if (!class_exists($expected) && !interface_exists($expected)) {
            throw new InvalidArgumentException('Unknown assertion class or interface "' . $expected . '".');
        }
    }
}

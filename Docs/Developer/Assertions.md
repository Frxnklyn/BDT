# Runtime assertions

Runtime code (Behat contexts, nodes, browser, formatters and traits) must use
`axenox\BDT\Behat\Common\Assert`. PHPUnit's `Assert` is allowed only under
`Tests/PHPUnit`. Do not use PHPUnit assertion imports or fully-qualified
`PHPUnit\Framework\Assert` calls in runtime code.

Methods are added to the BDT facade on demand only and must mirror PHPUnit
semantics, including parameter order, strict comparisons and invalid-input behavior.
The exception is `fail()`, which additionally accepts an optional previous throwable.
Unknown class or interface names are rejected by both instance assertions.
Like PHPUnit, `assertCount()` rejects one-shot generators instead of consuming them;
materialize an intended generator result before asserting its count.

## Why BDT owns runtime assertions

Since PHPUnit 11.3, formatting a failed assertion can read the PHPUnit runner's
configuration registry through a value exporter. Behat does not initialize that
registry. The symptom is
`Fatal error: assert(self::$instance instanceof Configuration)` (or a TypeError when
native assertions are disabled), replacing the useful failure message in the report,
database and step-result markdown.

The BDT facade preserves caller-supplied messages and throws
`axenox\BDT\Exceptions\AssertionFailedException`, a subclass of PHPUnit's
`AssertionFailedError`. Existing assertion catches still work, including table
content verification, which must not wrap expectation failures as infrastructure
errors. Failure construction uses neither a value exporter nor runner state.
PHPUnit unit tests continue using PHPUnit's assertions under its runner.

For failure collection, monitor logging and step references, see
[Error handling and log IDs](Error_handling_and_log_ids.md).

PHPUnit marks `AssertionFailedError` as internal and excludes it from its
backward-compatibility promise. BDT's dependency constraint supports
`^10 || ^11 || ^12 || ^13`; verify its hierarchy and constructor remain compatible
and runner-independent whenever a supported PHPUnit major changes.

## Preserve causes when converting failures

**When converting a caught exception into an assertion failure, pass it as previous;
the CDP detector only inspects causes below the deepest assertion.**

```php
Assert::fail($message, $exception);
```

This also applies when turning a failed TestResult into an assertion: preserve
`$result->getException()` instead of only copying its message.

The assertion itself and all outer wrappers, including an outer ChromeHangException,
are ignored. An assertion with no underlying cause never requests recovery, even
when its message contains "WebSocket". A genuine transport cause below it still does.

Connection detection recognizes WebSocket exception types and transport keywords in
the eligible segment. Recovery additionally recognizes ChromeHangException anywhere
in that segment. Without an assertion, the eligible segment is the whole chain.
This intentionally broadens recovery: a wrapped ChromeHangException without any
assertion now requests recovery too, but its type alone is not a connection error.

## Regression safeguards

- Facade parity tests compare passing and failing inputs against PHPUnit's assertions.
- The Registry-independence test starts a separate PHP process that loads only the
  Composer autoloader; running this check inside PHPUnit would initialize the Registry
  and conceal the original regression.
- Detector tests cover assertion precedence and recovery-only Chrome hangs.
- The architecture test scans all package PHP files except `Tests/PHPUnit` and nested
  `vendor` directories, preventing PHPUnit assertions from re-entering runtime code.

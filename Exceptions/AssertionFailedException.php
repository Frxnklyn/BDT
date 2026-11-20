<?php
namespace axenox\BDT\Exceptions;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Keeps BDT expectation failures compatible with existing PHPUnit assertion catches.
 *
 * The inherited constructor only initializes the exception and its serializable trace;
 * it does not depend on PHPUnit's runner configuration or value exporters.
 *
 * Extending PHPUnit's AssertionFailedError preserves existing catch blocks without edits.
 * It intentionally does not implement exface ExceptionInterface: the RuntimeException
 * wrapper in the AfterStep hook that logs failed steps owns Log-ID assignment.
 *
 * PHPUnit marks AssertionFailedError as internal, outside its backward-compatibility
 * promise. BDT currently supports PHPUnit ^10 || ^11 || ^12 || ^13, so each supported
 * major must retain a compatible hierarchy and runner-independent constructor.
 *
 * @author Copilot
 */
class AssertionFailedException extends AssertionFailedError
{
}

<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\Exceptions\RuntimeException;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\WorkbenchInterface;
use WeakMap;

/**
 * Collects UI5 test errors and correlates them with persisted log entries.
 *
 * A process-wide instance lets hooks and substeps share diagnostics without owning each other.
 */
class ErrorManager
{
    private static ?ErrorManager $instance = null;
    private array $errors = [];
    private float $lastErrorTime = 0;

    private ?string $lastLogId = null;
    /** @var WeakMap<\Throwable, string> */
    private WeakMap $loggedExceptions;
    /**
     * Sets the current failure's log ID.
     *
     * Frames must be able to restore their own ID after best-effort cleanup.
     */
    public function setLastLogId(?string $logId): void
    {
        $this->lastLogId = $logId;
    }
    
    /**
     * Returns the current failure's log ID.
     *
     * Step persistence uses this reference to link the recorded outcome to its original cause.
     */
    public function getLastLogId(): ?string
    {
        return $this->lastLogId;
    }
    
    /**
     * Initializes the logged-exception registry.
     *
     * Weak references avoid retaining every exception and its trace for the whole run.
     */
    private function __construct()
    {
        $this->loggedExceptions = new WeakMap();
    }

    /**
     * Returns the shared ErrorManager instance, creating it when needed.
     *
     * Hooks and substeps need one error collection and log-reference state within a process.
     */
    public static function getInstance(): ErrorManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Adds an error to the collection.
     *
     * Deduplication within one second prevents repeated probes from flooding the report.
     */
    public function addError(array $error, string $source): void
    {
        // Standardize the error object
        $standardError = $this->standardizeError($error, $source);

        // Generate hash to prevent duplicate errors
        $hash = $this->generateErrorHash($standardError);

        // Add the error if it's not a duplicate or if at least 1 second has passed since the last error
        $currentTime = microtime(true);
        if (!isset($this->errors[$hash]) || ($currentTime - $this->lastErrorTime) > 1) {
            $this->errors[$hash] = $standardError;
            $this->lastErrorTime = $currentTime;
        }
    }

    /**
     * Normalizes error fields and adds collection metadata.
     *
     * Comparable records support deduplication while retaining log IDs needed by formatters.
     */
    private function standardizeError(array $error, string $source): array
    {
        return [
            'type' => $error['type'] ?? 'Unknown',
            'message' => $error['message'] ?? '',
            'status' => $error['status'] ?? null,
            'url' => $error['url'] ?? '',
            'response' => $error['response'] ?? '',
            'source' => $source,
            'logId' => $error['logId'] ?? null,
            'timestamp' => microtime(true),
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
        ];
    }

    /**
     * Builds a hash identifying equivalent errors.
     *
     * Ignoring dynamic URL parameters lets repeated probes match the same failure.
     */
    private function generateErrorHash(array $error): string
    {
        // Clean dynamic parameters from URL
        $url = preg_replace('/[?&][^=]*=[^&]*/', '', $error['url'] ?? '');

        // Combine basic information for hash generation
        $hashContent = $error['type'] . '|' .
            $error['message'] . '|' .
            $error['status'] . '|' .
            $url;

        return md5($hashContent);
    }
    /**
     * Returns all collected errors.
     *
     * Formatters need the collection without owning the probes that populated it.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Reports whether errors have been collected.
     *
     * Callers use this to avoid inventing a fallback error when diagnostic evidence already exists.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    /**
     * Returns the first collected error, or null.
     *
     * Callers reporting one primary failure need a stable entry point into the collection.
     */
    public function getFirstError(): ?array
    {
        return reset($this->errors) ?: null;
    }
    /**
     * Clears collected errors and their deduplication timer.
     *
     * New browser work must not reuse stale diagnostics; persisted log references remain independent.
     */
    public function clearErrors(): void
    {
        $this->errors = [];
        $this->lastErrorTime = 0;
    }


    /**
     * Formats a collected error as readable text.
     *
     * Keeping context and its optional log reference together lets readers locate the original cause.
     */
    public function formatErrorMessage(array $error): string
    {
        // If this is a Behat exception, use the specialized formatter
        if (isset($error['type']) && $error['type'] === 'BehatException') {
            return $this->formatBehatExceptionMessage($error);
        }
            
        $message = "Source: " . ($error['source'] ?? 'Unknown') . "\n";
        $message .= $this->formatLogId($error['logId'] ?? null);
        $message .= "Message: " . ($error['message'] ?? 'No message available') . "\n";
        $message .= "URL: " . ($error['url'] ?? 'N/A') . "\n";
        $message .= "Type: " . ($error['type'] ?? 'N/A') . "\n";
        $message .= "Status: " . ($error['status'] ?? 'N/A') . "\n";

        if (!empty($error['response'])) {
            $message .= "Response: " . $error['response'] . "\n";
        }

        return $message;
    }

    /**
     * Formats a Behat exception and its optional trace.
     *
     * Behat failures need their cause and log reference alongside diagnostic traces.
     */
    public function formatBehatExceptionMessage(array $error): string
    {
        $message = "Source: " . ($error['source'] ?? 'Unknown') . "\n";
        $message .= $this->formatLogId($error['logId'] ?? null);
        $message .= "Message: " . ($error['message'] ?? 'No message available') . "\n";
        $message .= "Type: " . ($error['type'] ?? 'N/A') . "\n";
        $message .= "Status: " . ($error['status'] ?? 'N/A') . "\n";

        if (!empty($error['stack'])) {
            $message .= "\nStack Trace:\n" . $error['stack'] . "\n";
        }

        return $message;
    }

    /**
     * Formats an optional log-reference line.
     *
     * Both text formatters must use the same representation without inventing missing IDs.
     *
     * @param string|null $logId ID of a persisted log entry, if available.
     * @return string The log-reference line, or no text when no ID is available.
     */
    private function formatLogId(?string $logId): string
    {
        return $logId !== null && $logId !== '' ? "Log ID: " . $logId . "\n" : '';
    }

    /**
     * Builds a single-line markdown error with an optional persisted log ID.
     *
     * WHY NOT CliOutputPrinter: it takes the ID from the exception even when it was never logged.
     * The caller supplies the persisted ID instead, and line breaks must not split a list item.
     *
     * @param string $message Original failure message.
     * @param string|null $logId ID of the successfully written log entry, if available.
     * @return string A single-line markdown error with an optional log reference.
     */
    public static function buildErrorLine(string $message, ?string $logId = null): string
    {
        return '**ERROR:** ' . trim(StringDataType::replaceLineBreaks($message, ' '))
            . ($logId !== null && $logId !== '' ? ' (Log-ID: ' . $logId . ')' : '');
    }

    /**
     * Writes a housekeeping warning without changing the last log ID.
     *
     * The monitor can itself fail during cleanup. STDERR is the last-resort diagnostic boundary
     * so that a failed warning write cannot replace the exception currently being handled.
     *
     * @param WorkbenchInterface $workbench Workbench owning the warning logger.
     * @param string $message Housekeeping operation that failed.
     * @param \Throwable $e Failure to include in the warning.
     * @return void
     */
    public static function logWarning(WorkbenchInterface $workbench, string $message, \Throwable $e): void
    {
        $message = 'BDT: ' . $message . ': ' . $e->getMessage();
        try {
            $workbench->getLogger()->warning($message, ['exception' => $e]);
        } catch (\Throwable $loggingError) {
            // Diagnostic boundary: even a broken monitor must not abort browser error handling.
            fwrite(STDERR, $message . ' (warning logging failed: ' . $loggingError->getMessage() . ')' . PHP_EOL);
        }
    }

    /**
     * Logs an exception once and returns its persisted log ID.
     *
     * Only a successful logger write replaces the previous ID; local-only callers leave it untouched.
     * Logger failures propagate so callers can report them at their own failure-handling boundary.
     * Repeated logging of the same object reuses its persisted ID: page checks can rethrow a substep
     * failure into the AfterStep hook, which must not create another monitor entry for it.
     *
     * Usage:
     *     $logId = ErrorManager::getInstance()->logException($exception, $workbench);
     *
     * @param \Throwable $e Original failure to wrap and log.
     * @param WorkbenchInterface|null $workbench Optional workbench owning the logger.
     * @return string|null The new or previously persisted log ID, or null without a logger.
     * @throws \Throwable If the logger cannot write the exception.
     */
    public function logException(\Throwable $e, ?WorkbenchInterface $workbench = null): ?string
    {
        if ($workbench === null) {
            return null;
        }
        if (isset($this->loggedExceptions[$e])) {
            $logId = $this->loggedExceptions[$e];
            $this->setLastLogId($logId);
            return $logId;
        }

        $wrappedException = new RuntimeException(
            $e->getMessage(),
            null,
            $e
        );

        $workbench->getLogger()->logException($wrappedException);
        $logId = $wrappedException->getId();
        $this->loggedExceptions[$e] = $logId;
        $this->setLastLogId($logId);
        return $logId;
    }
    
    /**
     * Removes an exact error record from the collection.
     *
     * Consumers can discard handled diagnostics without clearing unrelated failures.
     */
    public function dropError(array $e): void
    {
        $key = array_search($e, $this->errors, true);

        if ($key !== false) {
            unset($this->errors[$key]);
        }
    }
}
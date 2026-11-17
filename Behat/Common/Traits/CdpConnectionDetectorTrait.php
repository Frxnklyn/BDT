<?php
namespace axenox\BDT\Behat\Common\Traits;

use axenox\BDT\Exceptions\ChromeHangException;
use PHPUnit\Framework\AssertionFailedError;
use WebSocket\Exception\ExceptionInterface as WebSocketExceptionInterface;

/**
 * Detects whether a throwable originates from a broken CDP/WebSocket connection.
 *
 * Shared by UI5WaitManager (session-level wait calls) and UI5AbstractNode
 * (direct DOM calls such as getAttribute, find, evaluateScript) so that the
 * detection logic lives in exactly one place.
 */
trait CdpConnectionDetectorTrait
{
    /**
     * Keeps transport retries and CDP escalation limited to genuine connection failures.
     *
     * Assertion messages and their wrappers can copy transport keywords from expected UI text.
     * Only causes below the deepest assertion may establish a connection failure. Without
     * assertions, a bare ChromeHangException remains a recovery concern, not a connection match.
     * A typed Chrome hang below an assertion is likewise considered only by recovery mode.
     *
     * @param \Throwable $e The throwable to inspect.
     * @return bool True if the eligible causes indicate a lost or unavailable CDP connection.
     */
    private function isCdpConnectionError(\Throwable $e): bool
    {
        return $this->hasCdpCause($e, false);
    }

    /**
     * Preserves hang recovery without letting assertion wrappers request a browser restart.
     *
     * A ChromeHangException anywhere in the cause chain requests recovery when no assertion exists. If an
     * assertion is present, even that typed wrapper is ignored unless an eligible cause below
     * the deepest assertion independently establishes a transport failure or Chrome hang.
     *
     * @param \Throwable $e The throwable caught by a hook or step.
     * @return bool True if the eligible causes require Chrome recovery.
     */
    private function requiresChromeRestart(\Throwable $e): bool
    {
        return $this->hasCdpCause($e, true);
    }

    /**
     * Applies assertion precedence once so connection detection and recovery share one cause boundary.
     *
     * Every assertion resets the accumulated verdict, discarding itself and all outer causes.
     * Walking to the end is essential: returning on a transport match could miss a deeper
     * assertion that invalidates it. After the last reset, only causes below the deepest
     * assertion can contribute, including typed Chrome hangs without a transport keyword in
     * recovery mode only. Connection mode still requires a WebSocket type or transport message.
     *
     * Connection detection uses WebSocket types and transport messages in the eligible segment.
     * Recovery additionally accepts a ChromeHangException anywhere in that segment. This
     * intentionally changes behavior: a wrapped Chrome hang without an assertion now requests
     * recovery too, rather than depending on a transport keyword in its wrapper.
     *
     * Known patterns and their sources:
     *  - "WebSocket"               → dmore/chrome-mink-driver handshake failure
     *  - "Connection refused"      → POSIX: port closed
     *  - "actively refused it"     → Windows Winsock RST (same root cause)
     *  - "No connection could be"  → Windows Winsock generic connect failure
     *  - "Unable to connect to tcp"→ phrity/net-stream SocketClient
     *  - "Could not connect"       → older dmore driver phrasing
     *  - "Server is closed"        → dmore driver: WebSocket server unreachable
     *  - "curl error"              → Guzzle curl transport failure
     *  - "Empty read" / "connection dead" → phrity/websocket FrameHandler: "Empty read; connection dead?" when the socket returns no data
     *  - "version information"     → dmore driver: Chrome not yet answering /json/version at
     *                                 connect time (transient at session start / after restart)
     *
     * @param \Throwable $e The thrown exception whose cause chain determines the result.
     * @param bool $recoveryMode Includes typed Chrome hangs anywhere in the eligible segment.
     * @return bool True if the eligible causes satisfy the requested detection mode.
     */
    private function hasCdpCause(\Throwable $e, bool $recoveryMode): bool
    {
        $detected = false;
        $current = $e;
        while ($current !== null) {
            if ($current instanceof AssertionFailedError) {
                // A deeper assertion invalidates every match in the segment above it.
                $detected = false;
                $current = $current->getPrevious();
                continue;
            }

            $msg = $current->getMessage();
            if ($current instanceof WebSocketExceptionInterface
                || ($recoveryMode && $current instanceof ChromeHangException)
                || str_contains($msg, 'WebSocket')
                || str_contains($msg, 'Connection refused')
                || str_contains($msg, 'actively refused it')
                || str_contains($msg, 'No connection could be')
                || str_contains($msg, 'Unable to connect to tcp')
                || str_contains($msg, 'Could not connect')
                || str_contains($msg, 'Server is closed')
                || str_contains($msg, 'curl error')
                || str_contains($msg, 'Empty read')
                || str_contains($msg, 'connection dead')
                // WHY: dmore/chrome-mink-driver throws "Could not fetch version information from
                // .../json/version ... Json Error: Syntax error" when it connects (lazily, on the
                // first visit) to a Chrome that is up but has not finished answering /json/version
                // yet - the classic first-step-of-scenario race. Without this pattern visitPath()
                // treats it as a hard error and re-throws on attempt 1, failing a scenario that a
                // single retry would have passed. Matching the stable "version information" wording
                // routes it into the existing CDP retry/backoff instead.
                || str_contains($msg, 'version information')
            ) {
                $detected = true;
            }
            $current = $current->getPrevious();
        }
        return $detected;
    }
}
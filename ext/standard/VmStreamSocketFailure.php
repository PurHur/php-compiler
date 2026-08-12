<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when stream_socket_* / fsockopen connect fails (php-src streamsfuncs.c / fsock.c; #10980, #30313).
 *
 * When {@see VmStreamSocketPure} calls host stream_socket_* under `@`, Zend's internal
 * `php_network_getaddresses` Warning is suppressed — re-emit it before Unable-to-connect (#30395).
 */
final class VmStreamSocketFailure
{
    public static function warnConnectFailed(
        Frame $frame,
        string $remote,
        string $errstr,
        string $function = 'stream_socket_client'
    ): void {
        if (null === $frame->vmContext) {
            return;
        }
        $script = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        // php-src php_network_getaddresses emits this as its own E_WARNING first.
        if (\str_starts_with($errstr, 'php_network_getaddresses:')) {
            $frame->vmContext->errors->triggerError(
                \sprintf('%s(): %s', $function, $errstr),
                ErrorReporter::E_WARNING,
                $script,
                $frame->vmContext,
                $frame
            );
        }
        $message = \sprintf(
            '%s(): Unable to connect to %s (%s)',
            $function,
            $remote,
            $errstr
        );
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            $script,
            $frame->vmContext,
            $frame
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when stream_socket_* / fsockopen connect fails (php-src streamsfuncs.c / fsock.c; #10980, #30313).
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
        $message = \sprintf(
            '%s(): Unable to connect to %s (%s)',
            $function,
            $remote,
            $errstr
        );
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when stream_socket_client() connect fails (php-src streamsfuncs.c; #10980).
 */
final class VmStreamSocketFailure
{
    public static function warnConnectFailed(Frame $frame, string $remote, string $errstr): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $message = \sprintf(
            'stream_socket_client(): Unable to connect to %s (%s)',
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

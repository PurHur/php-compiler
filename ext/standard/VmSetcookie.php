<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;
use PHPCompiler\Web\ResponseContext;

/**
 * setcookie() / setrawcookie() header emission with headers-sent guard (ext/standard/head.c, #10865).
 */
final class VmSetcookie
{
    public static function emit(Frame $frame, string $function, string $headerLine): bool
    {
        if (SapiOutput::headersSent()) {
            self::warnHeadersAlreadySent($frame);

            return false;
        }
        ResponseContext::addHeader($headerLine, false);

        return true;
    }

    private static function warnHeadersAlreadySent(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $file = SapiOutput::sentFile();
        $line = SapiOutput::sentLine();
        $origin = (null !== $file && '' !== $file)
            ? \sprintf(' (output started at %s:%d)', $file, $line)
            : '';
        $frame->vmContext->errors->triggerError(
            'Cannot modify header information - headers already sent'.$origin,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

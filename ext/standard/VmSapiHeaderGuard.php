<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;

/**
 * headers_sent guard for header()/setcookie() (ext/standard/head.c, #10865, #12085).
 */
final class VmSapiHeaderGuard
{
    public static function headerLineContainsNewline(string $line): bool
    {
        return (bool) preg_match('/[\r\n]/', $line);
    }

    public static function headersAlreadySent(Frame $frame): bool
    {
        return SapiOutput::headersSent();
    }

    public static function warnHeaderNewline(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            'Header may not contain more than a single header, new line detected',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    public static function warnHeadersAlreadySent(Frame $frame): void
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
            'Cannot modify header information - headers already sent by'.$origin,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

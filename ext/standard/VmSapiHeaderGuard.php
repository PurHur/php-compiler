<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;

/**
 * headers_sent guard for header()/setcookie()/http_response_code() (ext/standard/head.c, #10865, #12085, #28929).
 */
final class VmSapiHeaderGuard
{
    /** php-src head.c PHP_FUNCTION(http_response_code) via php_error_docref. */
    public const CANNOT_SET_RESPONSE_CODE = 'http_response_code(): Cannot set response code - headers already sent';

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

    /**
     * php-src head.c — refuse http_response_code($code) after SG(headers_sent) (#28929).
     */
    public static function warnCannotSetResponseCode(Frame $frame): void
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
            self::CANNOT_SET_RESPONSE_CODE.$origin,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\exif;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\ext\standard\VmString;

/**
 * E_WARNING for exif builtins when payload is readable but not JPEG/TIFF EXIF (#18573, #19231).
 *
 * php-src formats the path operand with php_basename() via php_error_docref().
 *
 * @see https://github.com/php/php-src/blob/master/ext/exif/exif.c exif_read_from_file()
 */
final class VmExifWarning
{
    public static function warnFileNotSupported(Frame $frame, string $function, string $path): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        // php-src php_error_docref(): function(basename): … — not the absolute path (#19231).
        $display = VmString::basename($path);
        $message = \sprintf('%s(%s): File not supported', $function, $display);
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

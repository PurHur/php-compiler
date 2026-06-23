<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * tempnam() path creation (php-src ext/standard/file.c, main/php_open_temporary_file.c, #4401).
 */
final class VmFsTempnam
{
    private const PREFIX_MAX = 64;

    public const NOTICE_MESSAGE = "tempnam(): file created in the system's temporary directory";

    public static function normalizePrefix(string $prefix): string
    {
        $base = VmString::basename($prefix, '');
        if (\strlen($base) >= self::PREFIX_MAX) {
            return \substr($base, 0, self::PREFIX_MAX - 1);
        }

        return $base;
    }

    public static function invoke(string $directory, string $prefix, Frame $frame): string|false
    {
        $pfx = self::normalizePrefix($prefix);
        $path = self::tryCreate($directory, $pfx);
        if (false !== $path) {
            return $path;
        }
        self::emitNotice($frame);
        $fallback = VmSysGetTempDirNative::resolve();
        if ('' === $fallback) {
            return false;
        }

        return self::tryCreate($fallback, $pfx);
    }

    private static function tryCreate(string $dir, string $prefix): string|false
    {
        if ('' === $dir) {
            return false;
        }
        $ffiPath = VmFsTempnamNative::mkstemp($dir, $prefix);
        if (false !== $ffiPath) {
            return $ffiPath;
        }

        return false;
    }

    private static function emitNotice(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::NOTICE_MESSAGE,
            ErrorReporter::E_NOTICE,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame,
            $frame->callSiteLine
        );
    }
}

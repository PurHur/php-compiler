<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

/**
 * tempnam() path creation (php-src ext/standard/file.c, main/php_open_temporary_file.c, #4401).
 */
final class VmFsTempnam
{
    private const PREFIX_MAX = 64;

    public const NOTICE_MESSAGE = "tempnam(): file created in the system's temporary directory";

    /**
     * php-src ext/standard/file.c — null $directory selects system temp dir (#14672);
     * caller strict_types rejects null (#18244, zend_verify_arg_type);
     * forward profile 8.4+ rejects null $directory like stub `string $directory` (#20960).
     */
    public static function resolveDirectoryArg(Variable $var, Frame $frame): string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            if (
                InternalStrictArg::isCallerStrict($frame)
                || VmString::requiresTypedPathStringOnForwardProfile()
            ) {
                throw new \TypeError(
                    'tempnam(): Argument #1 ($directory) must be of type string, null given'
                );
            }

            return VmSysGetTempDirNative::resolve();
        }

        return VmString::coercePathBuiltinArg($var, 'tempnam', 0, 'directory');
    }

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
        if ('' === $directory) {
            $fallback = VmSysGetTempDirNative::resolve();
            if ('' === $fallback) {
                return false;
            }

            return self::tryCreate($fallback, $pfx);
        }
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * glob() for VM — pure PHP via {@see VmFsGlobPure} (#4859, #7314, #12208).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(glob)
 * JIT/AOT: StringFsGlobVecJit.php (LLVM from PHP, no injected C runtime)
 */
final class VmFsGlob
{
    public const INVALID_FLAGS_WARNING =
        'glob(): At least one of the passed flags is invalid or not supported on this platform';

    public static function available(): bool
    {
        return VmFsGlobPure::available();
    }

    public static function hasInvalidFlags(int $flags): bool
    {
        return 0 !== ($flags & ~StdlibConstants::GLOB_AVAILABLE_FLAGS);
    }

    /**
     * @return list<string>|false
     */
    public static function glob(string $pattern, int $flags = 0, ?Frame $frame = null)
    {
        if (self::hasInvalidFlags($flags)) {
            self::warnInvalidFlags($frame);

            return false;
        }

        return VmFsGlobPure::glob($pattern, $flags);
    }

    public static function warnInvalidFlags(?Frame $frame): void
    {
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                self::INVALID_FLAGS_WARNING,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );

            return;
        }

        if (\function_exists('compiler_language_warning')) {
            compiler_language_warning(self::INVALID_FLAGS_WARNING);
        }
    }
}

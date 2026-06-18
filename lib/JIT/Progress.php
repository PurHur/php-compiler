<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\ProgressJitHelper;
use PHPCompiler\JIT\Builtin\ProgressNoteRuntime;

/** Optional JIT compile progress file for native AOT segfault triage (issue #816). */
final class Progress
{
    private static bool $pathResolved = false;

    /** @var string|null */
    private static $cachedPath = null;

    /** Emit a native progress breadcrumb during LLVM lowering (#6748). */
    public static function emitNativeNote(Context $context, string $message): void
    {
        ProgressNoteRuntime::emitCall($context, $message);
    }

    public static function noteFunction(string $name): void
    {
        ProgressJitHelper::noteFunction($name);
    }

    public static function notePhase(string $phase): void
    {
        ProgressJitHelper::notePhase($phase);
    }

    public static function noteEntry(string $entry): void
    {
        ProgressJitHelper::noteEntry($entry);
    }

    public static function readLast(?string $path = null): ?string
    {
        if (null === $path) {
            if (self::$pathResolved) {
                $path = self::$cachedPath;
            } else {
                self::$pathResolved = true;
                $env = getenv('PHP_COMPILER_JIT_PROGRESS_FILE');
                if (false !== $env && '' !== $env) {
                    self::$cachedPath = $env;
                    $path = self::$cachedPath;
                } else {
                    $path = null;
                }
            }
        }
        if (null === $path || !is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }
        $line = trim($raw);

        return '' === $line ? null : $line;
    }
}

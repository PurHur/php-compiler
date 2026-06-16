<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Block;
use PHPCompiler\Runtime;

/**
 * Per-process compile cache for phpc serve (issue #1887).
 *
 * Re-parsing entry scripts after exit/redirect can corrupt CFG operand scope metadata.
 * Included compile units (require) must also be cached so classes are not re-declared.
 */
final class ServeCompileCache
{
    /** @var array<string, array{0: int, 1: Block}> mtime => block */
    private static array $blocks = [];

    private static bool $enabled = false;

    private static bool $loading = false;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function isLoading(): bool
    {
        return self::$loading;
    }

    public static function reset(): void
    {
        self::$blocks = [];
        self::$enabled = false;
        self::$loading = false;
    }

    public static function get(Runtime $runtime, string $script, string $code): ?Block
    {
        return self::getFile($runtime, $script);
    }

    public static function getFile(Runtime $runtime, string $script): ?Block
    {
        $key = realpath($script);
        if (false === $key || '' === $key) {
            $key = $script;
        }
        $mtime = @filemtime($script) ?: 0;
        $cached = self::$blocks[$key] ?? null;
        if (null !== $cached && $cached[0] === $mtime) {
            return $cached[1];
        }

        self::$loading = true;
        try {
            $block = $runtime->parseAndCompileFile($script);
        } finally {
            self::$loading = false;
        }
        if (null !== $block) {
            self::$blocks[$key] = [$mtime, $block];
        }

        return $block;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Block;
use PHPCompiler\Runtime;

/**
 * Per-process compile cache for phpc serve (issue #1887).
 *
 * Re-parsing the same entry script after a prior request hit exit/redirect can corrupt
 * CFG operand scope metadata; reuse the first lowered block for a stable script path.
 */
final class ServeCompileCache
{
    /** @var array<string, array{0: int, 1: Block}> mtime => block */
    private static array $blocks = [];

    public static function reset(): void
    {
        self::$blocks = [];
    }

    public static function get(Runtime $runtime, string $script, string $code): ?Block
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

        $block = $runtime->parseAndCompile($code, $script);
        if (null !== $block) {
            self::$blocks[$key] = [$mtime, $block];
        }

        return $block;
    }
}

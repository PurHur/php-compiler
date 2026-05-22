<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Compile-time __DIR__ / __FILE__ / __LINE__ for JIT (#707, #715).
 */
final class ScriptMagic
{
    public static function stringForBlock(Block $block, int $kind): string
    {
        $path = $block->scriptPath();
        if (OpCode::SCRIPT_MAGIC_DIR === $kind) {
            return '' !== $path ? dirname($path) : '';
        }

        return $path;
    }

    public static function lineFromOpcode(int $line): int
    {
        return max(1, $line);
    }
}

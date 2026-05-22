<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Compile-time __DIR__ / __FILE__ for JIT using the unit's script path (#707).
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
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;

/**
 * AOT debug symbol helpers (#75).
 *
 * php-llvm does not yet expose DIBuilder FFI, so we encode the PHP source path in
 * link-visible symbol names and emit a global source-file string for gdb correlation.
 */
final class AotDebugSymbols
{
    public const ENV = 'PHP_COMPILER_AOT_DEBUG_SYMBOLS';

    public static function isEnabled(): bool
    {
        $env = getenv(self::ENV);

        return '1' === $env || 'true' === strtolower((string) $env);
    }

    public static function enable(): void
    {
        if (!\function_exists('putenv')) {
            return;
        }
        putenv(self::ENV.'=1');
        $_ENV[self::ENV] = '1';
        $_SERVER[self::ENV] = '1';
    }

    /**
     * LLVM function name for a script-scope {main} block when debug symbols are enabled.
     */
    public static function scriptMainFunctionName(Block $block): ?string
    {
        if (!self::isEnabled()) {
            return null;
        }
        if (null !== $block->func && '{main}' !== $block->func->name) {
            return null;
        }
        $path = $block->scriptPath();
        if ('' === $path || '-' === $path || 'Command line code' === $path) {
            return 'phpc_src_command_line_code';
        }

        return self::symbolFromPath($path);
    }

    public static function symbolFromPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = basename($normalized);
        if ('' === $base) {
            $base = $normalized;
        }
        $sanitized = preg_replace('/[^a-zA-Z0-9_]+/', '_', $base) ?? $base;
        $sanitized = trim((string) $sanitized, '_');
        if ('' === $sanitized) {
            $sanitized = 'script';
        }

        return 'phpc_src_'.$sanitized;
    }

    public static function linkFlag(): string
    {
        return self::isEnabled() ? '-g ' : '';
    }

    public static function compileFlag(): string
    {
        return self::isEnabled() ? '-g ' : '-O2 ';
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * MCJIT module init needs at least one user class (#4964, #5084).
 *
 * Empty user class bodies (zero declared properties) leave MCJIT modules that segfault on
 * execute until a property slot exists (#4954); pad at JIT prepare time only (bin/jit.php).
 */
final class JitMcjitEmbed
{
    private const EMPTY_CLASS_PAD = 'private bool $__phpcMcjitClassPad = false;';

    private const BOOTSTRAP_CLASS = 'class __phpc_mcjit_embed_bootstrap { public function __toString(): string { return ""; } } ';

    public static function prepareClassless(string $code): string
    {
        if (!preg_match('/^<\?php\s/', $code)) {
            return $code;
        }
        if (!preg_match('/\b(class|interface|trait|enum)\b/i', $code)) {
            return self::prependMcjitBootstrap($code);
        }

        $code = self::padEmptyUserClassesForMcjit($code);
        // Enum-only scripts still need a padded user class for MCJIT module init (#4964, #6487).
        if (preg_match('/\benum\b/i', $code) && !str_contains($code, '__phpc_mcjit_embed_bootstrap')) {
            return self::prependMcjitBootstrap($code);
        }

        return $code;
    }

    private static function prependMcjitBootstrap(string $code): string
    {
        return preg_replace(
            '/^<\?php\s*/',
            '<?php '.self::BOOTSTRAP_CLASS,
            $code,
            1
        ) ?? $code;
    }

    private static function padEmptyUserClassesForMcjit(string $code): string
    {
        $replaced = preg_replace_callback(
            '/\b(class\s+(?:[\w\\\\]+)\b[^<{]*)\{\s*\}/',
            static function (array $match): string {
                if (preg_match('/\binterface\s+/i', $match[1])) {
                    return $match[0];
                }

                return $match[1].'{ '.self::EMPTY_CLASS_PAD.' }';
            },
            $code
        );

        return null !== $replaced ? $replaced : $code;
    }
}

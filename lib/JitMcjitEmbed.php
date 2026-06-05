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

    public static function prepareClassless(string $code): string
    {
        if (!preg_match('/^<\?php\s/', $code)) {
            return $code;
        }
        if (!preg_match('/\b(class|interface|trait|enum)\b/i', $code)) {
            return preg_replace(
                '/^<\?php\s*/',
                '<?php class __phpc_mcjit_embed_bootstrap { public function __toString(): string { return ""; } } ',
                $code,
                1
            ) ?? $code;
        }

        return self::padEmptyUserClassesForMcjit($code);
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

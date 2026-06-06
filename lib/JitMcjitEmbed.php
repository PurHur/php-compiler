<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * MCJIT module init needs at least one user class (#4964, #5084).
 *
 * Empty user class bodies (zero declared properties) leave MCJIT modules that segfault on
 * execute until a property slot exists (#4954); pad at JIT prepare time only (bin/jit.php).
 * Const-only / method-only bodies without properties hit the same MCJIT gap (#6964).
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

        $code = self::padPropertylessUserClassesForMcjit($code);
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

    private static function padPropertylessUserClassesForMcjit(string $code): string
    {
        $replaced = preg_replace_callback(
            '/\b((?:abstract\s+|final\s+)?class\s+(?:[\w\\\\]+)\b[^<{]*)\{([^{}]*)\}/',
            static function (array $match): string {
                if (preg_match('/\binterface\s+/i', $match[1])) {
                    return $match[0];
                }
                $body = $match[2];
                if (str_contains($body, '__phpcMcjitClassPad')) {
                    return $match[0];
                }
                if (self::classBodyHasDeclaredProperty($body)) {
                    return $match[0];
                }
                $trimmed = trim($body);
                if ('' === $trimmed) {
                    return $match[1].'{ '.self::EMPTY_CLASS_PAD.' }';
                }

                return $match[1].'{ '.self::EMPTY_CLASS_PAD.' '.$trimmed.' }';
            },
            $code
        );

        return null !== $replaced ? $replaced : $code;
    }

    private static function classBodyHasDeclaredProperty(string $body): bool
    {
        if (preg_match('/\b(?:public|protected|private|var|readonly)\s+(?:[\w\\\\|?]+\s+)*\$/', $body)) {
            return true;
        }

        return (bool) preg_match(
            '/function\s+__construct\s*\([^)]*(?:public|protected|private|readonly)\s+[^)]*\$/',
            $body
        );
    }
}

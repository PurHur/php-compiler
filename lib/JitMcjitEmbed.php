<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * MCJIT module init needs at least one user class (#4964, #5084).
 *
 * Empty user class bodies (zero declared properties) leave MCJIT modules that segfault on
 * execute until a property slot exists (#4954); pad at JIT prepare time only (bin/jit.php).
 * Const-only / method-only bodies without properties hit the same MCJIT gap (#6964).
 * Constructor-promoted-only user classes need the same pad as trait-merged classes (#5091).
 */
final class JitMcjitEmbed
{
    /** MCJIT-only pad property name — hidden from var_export/get_object_vars (#10312). */
    public const CLASS_PAD_PROPERTY = '__phpcMcjitClassPad';

    private const EMPTY_CLASS_PAD = 'private bool $__phpcMcjitClassPad = false;';

    /** Readonly classes cannot declare properties with defaults (#8967, zend_compile.c). */
    private const EMPTY_READONLY_CLASS_PAD = 'private bool $__phpcMcjitClassPad;';

    private const BOOTSTRAP_CLASS = 'class __phpc_mcjit_embed_bootstrap { public function __toString(): string { return ""; } } ';

    public static function prepareClassless(string $code): string
    {
        if (!preg_match('/^<\?php\s/', $code)) {
            return $code;
        }
        if (!preg_match('/\b(class|interface|trait|enum)\b/i', $code)) {
            return self::prependMcjitBootstrap($code);
        }

        $needsReadonlyPromotedBootstrap = false;
        $code = self::padPropertylessUserClassesForMcjit($code, $needsReadonlyPromotedBootstrap);
        if ($needsReadonlyPromotedBootstrap && !str_contains($code, '__phpc_mcjit_embed_bootstrap')) {
            $code = self::prependMcjitBootstrap($code);
        }
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
            '<?php '.self::BOOTSTRAP_CLASS."\n",
            $code,
            1
        ) ?? $code;
    }

    private static function padPropertylessUserClassesForMcjit(string $code, bool &$needsReadonlyPromotedBootstrap): string
    {
        $replaced = preg_replace_callback(
            '/\b((?:(?:abstract\s+|final\s+|readonly\s+)*)class\s+(?:[\w\\\\]+)\b[^{]*)\{((?:[^{}]|\{[^{}]*\})*)\}/',
            static function (array $match) use (&$needsReadonlyPromotedBootstrap): string {
                if (preg_match('/\binterface\s+/i', $match[1])) {
                    return $match[0];
                }
                $body = $match[2];
                if (str_contains($body, '__phpcMcjitClassPad')) {
                    return $match[0];
                }
                if (self::classBodyHasNonPromotedDeclaredProperty($body)) {
                    return $match[0];
                }
                $isReadonlyClass = (bool) preg_match('/\breadonly\b/i', $match[1]);
                if ($isReadonlyClass && self::classBodyHasPromotedConstructorProperty($body)) {
                    $needsReadonlyPromotedBootstrap = true;

                    return $match[0];
                }
                $trimmed = trim($body);
                $pad = $isReadonlyClass ? self::EMPTY_READONLY_CLASS_PAD : self::EMPTY_CLASS_PAD;
                if ('' === $trimmed) {
                    return $match[1].'{ '.$pad.' }';
                }

                return $match[1].'{ '.$pad.' '.$trimmed.' }';
            },
            $code
        );

        return null !== $replaced ? $replaced : $code;
    }

    private static function classBodyHasNonPromotedDeclaredProperty(string $body): bool
    {
        $stripped = preg_replace(
            '/function\s+__construct\s*\([^)]*\)/',
            'function __construct()',
            $body
        ) ?? $body;

        return (bool) preg_match(
            '/\b(?:public|protected|private|var|readonly)\s+(?:[\w\\\\|?]+\s+)*\$/',
            $stripped
        );
    }

    private static function classBodyHasPromotedConstructorProperty(string $body): bool
    {
        return (bool) preg_match(
            '/function\s+__construct\s*\([^)]*(?:public|protected|private|readonly)\s+[^)]*\$/',
            $body
        );
    }

    /** Internal MCJIT embed slot — not user-visible in debug/var_export (#10312). */
    public static function isEmbedClassPadProperty(string $name): bool
    {
        return self::CLASS_PAD_PROPERTY === $name
            || strtolower(self::CLASS_PAD_PROPERTY) === strtolower($name);
    }
}

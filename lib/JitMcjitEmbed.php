<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * MCJIT module init needs at least one user class (#4964, #5084).
 */
final class JitMcjitEmbed
{
    public static function prepareClassless(string $code): string
    {
        if (preg_match('/\b(class|interface|trait|enum)\b/i', $code)) {
            return $code;
        }
        if (!preg_match('/^<\?php\s/', $code)) {
            return $code;
        }

        return preg_replace(
            '/^<\?php\s*/',
            '<?php class __phpc_mcjit_embed_bootstrap { public function __toString(): string { return ""; } } ',
            $code,
            1
        ) ?? $code;
    }
}

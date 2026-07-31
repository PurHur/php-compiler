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

    /** MCJIT pad on readonly classes omits default initializer (#8967, zend_compile.c). */
    private const EMPTY_READONLY_CLASS_PAD = 'private bool $__phpcMcjitClassPad;';

    private const BOOTSTRAP_CLASS = 'class __phpc_mcjit_embed_bootstrap { public function __toString(): string { return ""; } } ';

    public static function prepareClassless(string $code): string
    {
        if (!preg_match('/<\?php\b/i', $code, $openTag, PREG_OFFSET_CAPTURE)) {
            return $code;
        }
        $phpOffset = (int) $openTag[0][1];
        if ($phpOffset > 0) {
            return substr($code, 0, $phpOffset).self::preparePhpSegment(substr($code, $phpOffset));
        }

        return self::preparePhpSegment($code);
    }

    private static function preparePhpSegment(string $code): string
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
        // Match against comment-blanked text so docblock phrases like
        // "class constant E::a" cannot span into a following class/enum (#25929).
        $mask = self::blankPhpCommentsPreservingLength($code);
        if (!preg_match_all(
            '/\b((?:(?:abstract\s+|final\s+|readonly\s+)*)class\s+(?:[\w\\\\]+)\b[^{]*)\{((?:[^{}]|\{[^{}]*\})*)\}/',
            $mask,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return $code;
        }

        $out = $code;
        // Apply from the end so earlier offsets stay valid.
        for ($i = \count($matches[0]) - 1; $i >= 0; --$i) {
            $full = $matches[0][$i][0];
            $offset = $matches[0][$i][1];
            $header = $matches[1][$i][0];
            $body = $matches[2][$i][0];
            if (preg_match('/\binterface\s+/i', $header)) {
                continue;
            }
            if (preg_match('/\benum\b/i', $full)) {
                continue;
            }
            // Same slices from the real source (comments restored).
            $realFull = substr($code, $offset, \strlen($full));
            $headerLen = \strlen($header);
            $realHeader = substr($code, $offset, $headerLen);
            $realBody = substr($code, $offset + $headerLen + 1, \strlen($body));
            if (str_contains($realBody, '__phpcMcjitClassPad')) {
                continue;
            }
            if (self::classBodyHasNonPromotedDeclaredProperty($realBody)) {
                continue;
            }
            $isReadonlyClass = (bool) preg_match('/\breadonly\b/i', $realHeader);
            if ($isReadonlyClass && self::classBodyHasPromotedConstructorProperty($realBody)) {
                $needsReadonlyPromotedBootstrap = true;

                continue;
            }
            $trimmed = trim($realBody);
            $pad = $isReadonlyClass ? self::EMPTY_READONLY_CLASS_PAD : self::EMPTY_CLASS_PAD;
            $replacement = '' === $trimmed
                ? $realHeader.'{ '.$pad.' }'
                : $realHeader.'{ '.$pad.' '.$trimmed.' }';
            $out = substr($out, 0, $offset).$replacement.substr($out, $offset + \strlen($realFull));
        }

        return $out;
    }

    /**
     * Replace // and /* * / comments with spaces so regex class matching ignores them (#25929).
     */
    private static function blankPhpCommentsPreservingLength(string $code): string
    {
        $len = \strlen($code);
        $out = $code;
        $i = 0;
        while ($i < $len) {
            if ($i + 1 < $len && '/' === $out[$i] && '*' === $out[$i + 1]) {
                $end = strpos($out, '*/', $i + 2);
                if (false === $end) {
                    break;
                }
                $end += 2;
                $out = substr($out, 0, $i).str_repeat(' ', $end - $i).substr($out, $end);
                $i = $end;

                continue;
            }
            if ($i + 1 < $len && '/' === $out[$i] && '/' === $out[$i + 1]) {
                $end = $i + 2;
                while ($end < $len && "\n" !== $out[$end] && "\r" !== $out[$end]) {
                    ++$end;
                }
                $out = substr($out, 0, $i).str_repeat(' ', $end - $i).substr($out, $end);
                $i = $end;

                continue;
            }
            ++$i;
        }

        return $out;
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

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
 * Interface-only scripts need the embed bootstrap class (no property pad path, #27012).
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
        // Require a declaration form — `$class` / `get_class` must not suppress the embed
        // bootstrap (word-boundary `\bclass\b` matches those and forces a broken classless
        // MCJIT path; #27156).
        if (!preg_match('/\b(class|interface|trait|enum)\s+([a-zA-Z_\x80-\xff\\\\]|\{)/i', $code)) {
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
        // Interface-only scripts take the full MCJIT path (requiresVmLowering=false) but never
        // receive a class property pad — without bootstrap, MCJIT segfaults (#27012 / #4964).
        if (
            preg_match('/\binterface\b/i', $code)
            && !preg_match('/\bclass\s+/i', $code)
            && !str_contains($code, '__phpc_mcjit_embed_bootstrap')
        ) {
            return self::prependMcjitBootstrap($code);
        }

        return $code;
    }

    private static function prependMcjitBootstrap(string $code): string
    {
        // Namespace must be the first statement in the compilation unit (php-src
        // zend_language_parser.y / nikic php-parser). Prepending the MCJIT pad class
        // before `namespace` breaks bracketed and unbracketed scripts (#28002).
        if (self::phpSegmentHasNamespaceDeclaration($code)) {
            return self::appendMcjitBootstrapForNamespaced($code);
        }

        return preg_replace(
            '/^<\?php\s*/',
            '<?php '.self::BOOTSTRAP_CLASS."\n",
            $code,
            1
        ) ?? $code;
    }

    /**
     * True when the PHP segment declares a namespace (comments/strings blanked).
     *
     * Matches `namespace Foo;`, `namespace Foo {`, and `namespace {` — not the
     * relative name qualifier `namespace\Foo`.
     */
    private static function phpSegmentHasNamespaceDeclaration(string $code): bool
    {
        $mask = self::blankPhpOpaqueRegionsPreservingLength($code);

        return (bool) preg_match('/\bnamespace(\s*\{|\s+[\w\\\\]+)/i', $mask);
    }

    /**
     * Inject the MCJIT bootstrap without preceding `namespace` / `declare`.
     *
     * Bracketed files: append a global `namespace { … }` block (multiple global
     * blocks are legal). Unbracketed files: append the class at EOF so it lands
     * in the file's single namespace (cannot mix bracketed + unbracketed).
     */
    private static function appendMcjitBootstrapForNamespaced(string $code): string
    {
        $mask = self::blankPhpOpaqueRegionsPreservingLength($code);
        $bootstrap = rtrim(self::BOOTSTRAP_CLASS);
        if (preg_match('/\bnamespace(?:\s+[\w\\\\]+)?\s*\{/i', $mask)) {
            return rtrim($code)."\nnamespace { ".$bootstrap." }\n";
        }

        return rtrim($code)."\n".$bootstrap."\n";
    }

    private static function padPropertylessUserClassesForMcjit(string $code, bool &$needsReadonlyPromotedBootstrap): string
    {
        // Match against comment/string-blanked text so:
        // - docblock phrases like "class constant E::a" cannot span into a following class/enum (#25929)
        // - `eval("class Foo {}")` / string payloads are not rewritten (pad is for top-level JIT IR only, #26424)
        $mask = self::blankPhpOpaqueRegionsPreservingLength($code);
        // Brace-balanced scan: the old single-nesting regex missed methods that contain
        // Closures / nested blocks, so empty classes with `function () { ... }` never got
        // the MCJIT pad and segfaulted on execute (#27163 / #4954).
        $matches = self::findBraceBalancedClassDeclarations($mask);
        if ([] === $matches) {
            return $code;
        }

        $out = $code;
        // Apply from the end so earlier offsets stay valid.
        for ($i = \count($matches) - 1; $i >= 0; --$i) {
            [$offset, $header, $body] = $matches[$i];
            $fullLen = \strlen($header) + 1 + \strlen($body) + 1;
            $full = substr($mask, $offset, $fullLen);
            if (preg_match('/\binterface\s+/i', $header)) {
                continue;
            }
            if (preg_match('/\benum\b/i', $full)) {
                continue;
            }
            // Same slices from the real source (comments restored).
            $headerLen = \strlen($header);
            $realHeader = substr($code, $offset, $headerLen);
            $realBody = substr($code, $offset + $headerLen + 1, \strlen($body));
            $realFull = substr($code, $offset, $fullLen);
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
     * @return list<array{0: int, 1: string, 2: string}> offset, header (through `{` exclusive), body
     */
    private static function findBraceBalancedClassDeclarations(string $mask): array
    {
        $matches = [];
        if (!preg_match_all(
            '/\b((?:(?:abstract\s+|final\s+|readonly\s+)*)class\s+(?:[\w\\\\]+)\b[^{]*)\{/',
            $mask,
            $headers,
            PREG_OFFSET_CAPTURE
        )) {
            return $matches;
        }
        $len = \strlen($mask);
        foreach ($headers[1] as $i => $headerMatch) {
            $header = $headerMatch[0];
            $headerOffset = $headerMatch[1];
            $openBrace = $headers[0][$i][1] + \strlen($headers[0][$i][0]) - 1;
            if ($openBrace < 0 || $openBrace >= $len || '{' !== $mask[$openBrace]) {
                continue;
            }
            $depth = 1;
            $bodyStart = $openBrace + 1;
            $j = $bodyStart;
            for (; $j < $len; ++$j) {
                $ch = $mask[$j];
                if ('{' === $ch) {
                    ++$depth;
                } elseif ('}' === $ch) {
                    --$depth;
                    if (0 === $depth) {
                        break;
                    }
                }
            }
            if (0 !== $depth || $j >= $len) {
                continue;
            }
            $body = substr($mask, $bodyStart, $j - $bodyStart);
            $matches[] = [$headerOffset, $header, $body];
        }

        return $matches;
    }

    /**
     * Blank comments, string literals, and heredoc/nowdoc bodies (spaces; newlines kept)
     * so regex class matching only sees real declarations (#25929, #26424).
     *
     * Length-preserving so match offsets map back into the original source.
     */
    private static function blankPhpOpaqueRegionsPreservingLength(string $code): string
    {
        if ('' === $code) {
            return $code;
        }
        $tokens = @token_get_all($code);
        if ([] === $tokens) {
            return self::blankPhpCommentsPreservingLengthFallback($code);
        }
        $out = '';
        $inHeredoc = false;
        foreach ($tokens as $token) {
            if (\is_string($token)) {
                if ($inHeredoc) {
                    $out .= preg_replace('/[^\r\n]/', ' ', $token) ?? $token;
                    continue;
                }
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (\T_START_HEREDOC === $id) {
                $inHeredoc = true;
                $out .= $text;
                continue;
            }
            if (\T_END_HEREDOC === $id) {
                $inHeredoc = false;
                $out .= $text;
                continue;
            }
            $opaque = $inHeredoc
                || \T_COMMENT === $id
                || \T_DOC_COMMENT === $id
                || \T_CONSTANT_ENCAPSED_STRING === $id
                || \T_ENCAPSED_AND_WHITESPACE === $id;
            if ($opaque) {
                $out .= preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
                continue;
            }
            $out .= $text;
        }

        // token_get_all can drop a trailing incomplete fragment; keep length exact for offsets.
        $outLen = \strlen($out);
        $codeLen = \strlen($code);
        if ($outLen < $codeLen) {
            $out .= str_repeat(' ', $codeLen - $outLen);
        } elseif ($outLen > $codeLen) {
            $out = substr($out, 0, $codeLen);
        }

        return $out;
    }

    /**
     * Fallback when tokenization yields nothing — comments only (#25929).
     */
    private static function blankPhpCommentsPreservingLengthFallback(string $code): string
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

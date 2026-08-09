<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Rewrite PHP 8.4 asymmetric property visibility for nikic/php-parser 4.x (#3165).
 *
 * Transforms asymmetric visibility property syntax into a parseable form plus an internal marker
 * comment consumed by PHPCfg (php-src: Zend/zend_compile.c ZEND_ACC_*_SET flags).
 */
final class AsymmetricVisibilityRewriter
{
    private const MARKER_PREFIX_SET = 'phpc-asymmetric-set:';
    private const MARKER_PREFIX_GET = 'phpc-asymmetric-get:';
    private const MARKER_PREFIX_EXPLICIT_READ = 'phpc-asymmetric-explicit-read';

    /** @internal Marker when source declares an explicit read modifier before set (#15995). */
    public const EXPLICIT_READ_MARKER_PATTERN = '/\/\*\s*phpc-asymmetric-explicit-read\s*\*\//i';

    /** Self-host parser rejects bare asymmetric modifier tokens outside declarations (#1492). */
    private const SET_MODIFIER_NEEDLE = '('.'set'.')';
    private const GET_MODIFIER_NEEDLE = '('.'get'.')';

    /** php-src: Zend/zend_compile.c — zend_add_member_modifier() duplicate PPP / PPP_SET (#6774). */
    public const MULTIPLE_MODIFIERS_MESSAGE = 'Multiple access type modifiers are not allowed';

    /**
     * php-src: Zend/zend_compile.c — static property + asymmetric set on PHP 8.4 (#29389).
     *
     * Zend 8.4 fatals with this string (not {@see MULTIPLE_MODIFIERS_MESSAGE}); PHP 8.5 accepts
     * static aviz ({@see CompilerVersion::supportsStaticAsymmetricVisibility()}).
     */
    public const STATIC_ASYMMETRIC_VISIBILITY_MESSAGE = 'Static property may not have asymmetric visibility';

    /** Actionable DX when asymmetric visibility syntax is rejected on the reference profile (#17695). */
    public const REFERENCE_PROFILE_ASYMMETRIC_VISIBILITY_HINT = 'Asymmetric visibility requires PHP_COMPILER_PROFILE=8.4 (PHP 8.4 forward profile)';

    /** php-src: Zend/zend_language_scanner.l — invalid set/read modifier ordering on reference profile (#15446). */
    public const BARE_SET_WITHOUT_READ_MESSAGE = 'syntax error, unexpected token ")", expecting variable';

    /** php-src: Zend/zend_language_parser.y — parenthesized `(private(set))` on promoted params gated to 8.4+ (#16495). */
    public const PROMOTED_PARENTHESIZED_SET_MESSAGE = 'syntax error, unexpected token "%s"';

    /**
     * @internal Marker embedded in source for PHPCfg to recover set visibility.
     */
    public const MARKER_PATTERN = '/\/\*\s*phpc-asymmetric-set:(public|protected|private)\s*\*\//i';

    /** @internal Marker for asymmetric read visibility (#5059). */
    public const GET_MARKER_PATTERN = '/\/\*\s*phpc-asymmetric-get:(public|protected|private)\s*\*\//i';

    public static function containsAsymmetricVisibilitySyntax(string $source): bool
    {
        // php-src: Zend/zend_language_scanner.l ST_NOWDOC/ST_HEREDOC — body is data, not tokens (#24460).
        return self::hasAsymmetricVisibilitySyntax(self::blankOpaqueRegions($source));
    }

    /** 1-based line of first `(set)` / `(get)` outside comments and string literals, or 1 (#24460). */
    public static function findFirstAsymmetricSyntaxLine(string $source): int
    {
        $inspectable = self::blankOpaqueRegions($source);
        foreach (['(set)', '(get)'] as $needle) {
            $pos = stripos($inspectable, $needle);
            if (false !== $pos) {
                return substr_count(substr($inspectable, 0, $pos), "\n") + 1;
            }
        }

        return 1;
    }

    /**
     * 1-based source line of the first multiple-access-modifier violation, or 0 when none (#12576).
     *
     * Used on the Zend 8.2 reference profile where asymmetric set syntax is otherwise rejected with a generic
     * parser message — explicit read plus set visibility must still match Zend compile fatal.
     */
    public static function findMultipleAccessModifierLine(string $source): int
    {
        $inspectable = self::blankOpaqueRegions($source);
        $lineNum = 0;
        foreach (explode("\n", $inspectable) as $line) {
            ++$lineNum;
            if (!self::isInspectableAsymmetricLine($line, self::SET_MODIFIER_NEEDLE)) {
                continue;
            }
            if (self::lineViolatesMultipleSetModifierRulesForReferenceProfile($line)) {
                return $lineNum;
            }
            // Static aviz is PHP 8.5+ (#26239); ≤8.4 still treat read+set on static as fatal (#7013 / #29389).
            if (!CompilerVersion::supportsStaticAsymmetricVisibility()
                && self::lineViolatesStaticAsymmetricSetRules($line)) {
                return $lineNum;
            }
        }

        if (!preg_match('/\b__construct\b/i', $inspectable) || !preg_match('/\(\s*set\s*\)/i', $inspectable)) {
            return 0;
        }

        $offset = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $inspectable, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            $paramsText = self::extractBalancedParenContent($inspectable, $openPos);
            if (null !== $paramsText && self::paramsViolateMultipleSetModifierRulesForReferenceProfile($paramsText)) {
                $constructLine = substr_count(substr($inspectable, 0, $openPos), "\n") + 1;
                $relative = self::offsetOfMultipleSetModifierInParams($paramsText);
                if ($relative >= 0) {
                    return $constructLine + substr_count(substr($paramsText, 0, $relative), "\n");
                }

                return $constructLine;
            }
            $offset = $openPos + 1;
        }

        return 0;
    }

    /** 1-based line of first bare `(set)` without explicit read visibility, or 0 (#15446). */
    public static function findBareSetModifierLine(string $source): int
    {
        $inspectable = self::blankOpaqueRegions($source);
        $lineNum = 0;
        foreach (explode("\n", $inspectable) as $line) {
            ++$lineNum;
            if (!self::isInspectableAsymmetricLine($line, self::SET_MODIFIER_NEEDLE)) {
                continue;
            }
            if (self::lineIsHookBlockSetModifier($line)) {
                continue;
            }
            if (self::lineHasBareSetModifierWithoutRead($line)) {
                return $lineNum;
            }
        }

        if (!preg_match('/\b__construct\b/i', $inspectable) || !preg_match('/\(\s*set\s*\)/i', $inspectable)) {
            return 0;
        }

        $offset = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $inspectable, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            $paramsText = self::extractBalancedParenContent($inspectable, $openPos);
            if (null !== $paramsText) {
                $relative = self::offsetOfBareSetModifierInParams($paramsText);
                if ($relative >= 0) {
                    return substr_count(substr($inspectable, 0, $openPos), "\n") + 1
                        + substr_count(substr($paramsText, 0, $relative), "\n");
                }
            }
            $offset = $openPos + 1;
        }

        return 0;
    }

    /**
     * Reference-profile reject message for multiple-modifier detection (#12576, #17832).
     *
     * php-src-strict: Zend 8.2 treats unparenthesized `public private(set)` as duplicate access
     * modifiers — same fatal as `public protected private` etc. Profile gate hints apply only when
     * syntax is otherwise valid on the forward profile but blocked by {@see CompilerVersion}.
     */
    public static function referenceProfileMultipleModifierMessage(string $source, int $line): string
    {
        return self::MULTIPLE_MODIFIERS_MESSAGE;
    }

    private static function sourceLineAt(string $source, int $line): string
    {
        if ($line < 1) {
            return '';
        }
        $lines = explode("\n", $source);

        return $lines[$line - 1] ?? '';
    }

    /**
     * First parenthesized asymmetric set modifier `(private(set))` for Zend-aligned diagnostics (#16452).
     *
     * @return array{line: int, token: string}|null
     */
    public static function findParenthesizedAsymmetricSetModifierError(string $source): ?array
    {
        $inspectable = self::blankOpaqueRegions($source);
        if (!preg_match(
            '/\(\s*(?<token>private|protected|public)\s*\(\s*set\s*\)\s*\)/i',
            $inspectable,
            $match,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $token = strtolower($match['token'][0]);
        $tokenPos = $match['token'][1];

        return [
            'line' => substr_count(substr($inspectable, 0, $tokenPos), "\n") + 1,
            'token' => $token,
        ];
    }

    public static function rewrite(string $source): string
    {
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            return $source;
        }
        [$masked, $map] = self::maskLiteralsAndComments($source);
        if (!self::hasAsymmetricVisibilitySyntax($masked)) {
            return $source;
        }

        $masked = self::rewriteSetModifiers($masked);
        $masked = self::rewriteGetModifiers($masked);

        return self::unmaskLiteralsAndComments($masked, $map);
    }

    private static function hasAsymmetricVisibilitySyntax(string $source): bool
    {
        return false !== stripos($source, self::SET_MODIFIER_NEEDLE)
            || false !== stripos($source, self::GET_MODIFIER_NEEDLE);
    }

    /**
     * Blank comments / string / heredoc-nowdoc bodies while preserving newlines (#24460).
     *
     * Detection helpers use this so asymmetric-visibility text inside ST_NOWDOC / ST_HEREDOC
     * (and ordinary string literals) is not treated as declarations — matching Zend's scanner.
     */
    private static function blankOpaqueRegions(string $source): string
    {
        $tokens = token_get_all($source);
        $out = '';
        $inHeredoc = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($inHeredoc) {
                    $out .= preg_replace('/[^\r\n]/', ' ', $token) ?? $token;
                    continue;
                }
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (T_START_HEREDOC === $id) {
                $inHeredoc = true;
                $out .= $text;
                continue;
            }
            if (T_END_HEREDOC === $id) {
                $inHeredoc = false;
                $out .= $text;
                continue;
            }
            $opaque = $inHeredoc
                || T_COMMENT === $id
                || T_DOC_COMMENT === $id
                || T_CONSTANT_ENCAPSED_STRING === $id
                || T_ENCAPSED_AND_WHITESPACE === $id;
            if ($opaque) {
                $out .= preg_replace('/[^\r\n]/', ' ', $text) ?? $text;
                continue;
            }
            $out .= $text;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function maskLiteralsAndComments(string $source): array
    {
        $tokens = token_get_all($source);
        $masked = '';
        $map = [];
        $index = 0;
        $inHeredoc = false;
        foreach ($tokens as $token) {
            if (is_string($token)) {
                if ($inHeredoc) {
                    $placeholder = "\0PHPC_ASYM_MASK_{$index}\0";
                    $map[$placeholder] = $token;
                    $masked .= $placeholder;
                    ++$index;
                    continue;
                }
                $masked .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (T_START_HEREDOC === $id) {
                $inHeredoc = true;
                $masked .= $text;
                continue;
            }
            if (T_END_HEREDOC === $id) {
                $inHeredoc = false;
                $masked .= $text;
                continue;
            }
            $opaque = $inHeredoc
                || T_COMMENT === $id
                || T_DOC_COMMENT === $id
                || T_CONSTANT_ENCAPSED_STRING === $id
                || T_ENCAPSED_AND_WHITESPACE === $id;
            if ($opaque) {
                $placeholder = "\0PHPC_ASYM_MASK_{$index}\0";
                $map[$placeholder] = $text;
                $masked .= $placeholder;
                ++$index;
                continue;
            }
            $masked .= $text;
        }

        return [$masked, $map];
    }

    /**
     * @param array<string, string> $map
     */
    private static function unmaskLiteralsAndComments(string $masked, array $map): string
    {
        if ([] === $map) {
            return $masked;
        }

        return strtr($masked, $map);
    }

    private static function rewriteSetModifiers(string $source): string
    {
        if (!self::hasAsymmetricVisibilitySyntax($source)) {
            return $source;
        }

        // Static aviz reject before generic multiple-modifier checks so PROFILE=8.4
        // emits Zend's "Static property may not have asymmetric visibility" (#29389).
        self::rejectAsymmetricSetOnStaticProperty($source);
        self::rejectExplicitPublicBeforeSetModifier($source);
        self::rejectExplicitPublicAfterSetModifier($source);
        self::rejectPromotedParamParenthesizedAsymmetricSet($source);
        self::rejectPromotedParamMultipleAccessModifiers($source);
        self::rejectBareSetModifierWithoutRead($source);

        $hasUnsupportedPropertyParenSet = !CompilerVersion::supportsParenthesizedAsymmetricSetModifier()
            && null !== self::findParenthesizedAsymmetricSetModifierError($source);

        // Mid-modifiers (`static` / `readonly`) may sit between get-vis and set-vis.
        // Without consuming them, `public readonly private(set)` matches only at `private(set)`
        // and injects a second implicit `public` — nikic then fatals (#29387).
        // php-src: Zend/zend_language_parser.y — property_modifiers; trailing mid stays after match.
        $midModifiers = '(?P<mid>(?:(?:static|readonly)\s+)*)';

        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $source = (string) preg_replace_callback(
                '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
                .'(?P<readBefore>(?:(?:public|protected|private)\s+)?)'
                .$midModifiers
                .'\(\s*(?P<set>public|protected|private)\s*\(\s*set\s*\)\s*\)\s*/i',
                static function (array $m): string {
                    $set = strtolower($m['set']);
                    $readBefore = trim($m['readBefore']);
                    $explicitReadMarker = '';
                    if ('' !== $readBefore) {
                        $readPrefix = $readBefore.' ';
                        $explicitReadMarker = '/*'.self::MARKER_PREFIX_EXPLICIT_READ.'*/ ';
                    } else {
                        $readPrefix = self::implicitReadPrefixForBareSetModifier($set);
                    }

                    return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_SET.$set.'*/ '.$explicitReadMarker.$readPrefix.$m['mid'];
                },
                $source
            );
        }

        if ($hasUnsupportedPropertyParenSet) {
            return $source;
        }

        return (string) preg_replace_callback(
            '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
            .'(?P<readBefore>(?:(?:public|protected|private)\s+)?)'
            .$midModifiers
            .'(?P<set>public|protected|private)\s*\(\s*set\s*\)\s*'
            .'(?P<readAfter>(?:(?:public|protected|private)\s+)?)/i',
            static function (array $m): string {
                $set = strtolower($m['set']);
                $readBefore = trim($m['readBefore']);
                $readAfter = trim($m['readAfter']);
                $explicitReadMarker = '';
                if ('' !== $readAfter) {
                    $readPrefix = $readAfter.' ';
                    $explicitReadMarker = '/*'.self::MARKER_PREFIX_EXPLICIT_READ.'*/ ';
                } elseif ('' !== $readBefore) {
                    $readPrefix = $readBefore.' ';
                    $explicitReadMarker = '/*'.self::MARKER_PREFIX_EXPLICIT_READ.'*/ ';
                } else {
                    $readPrefix = self::implicitReadPrefixForBareSetModifier($set);
                }

                // Preserve leading mid (`public readonly private(set)`, `public static private(set)`);
                // trailing static/readonly after set stays after the match (#26239, #29387).
                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_SET.$set.'*/ '.$explicitReadMarker.$readPrefix.$m['mid'];
            },
            $source
        );
    }

    /**
     * Duplicate set modifier on the same visibility is a compile fatal (#6774, #11656).
     */
    private static function rejectExplicitPublicBeforeSetModifier(string $source): void
    {
        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (self::lineViolatesMultipleSetModifierRules($line)) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        });
    }

    /** Explicit read public after public-set duplicates implicit public read (#6589, #6774). */
    private static function rejectExplicitPublicAfterSetModifier(string $source): void
    {
        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (self::lineViolatesMultipleSetModifierRules($line)) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        });
    }


    private static function lineIsHookBlockSetModifier(string $line): bool
    {
        return 1 === preg_match('/\b(?:private|protected|public)\s*\(\s*set\s*\)\s*;/i', $line);
    }

    private static function lineHasBareSetModifierWithoutRead(string $line): bool
    {
        if (!preg_match('/\(\s*set\s*\)/i', $line)) {
            return false;
        }

        if (preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?:(?:static|readonly)\s+)*(?:'
            .'\((?:public|protected|private)\s*\(\s*set\s*\)\)|'
            .'(?:public|protected|private)\s*\(\s*set\s*\)'
            .')/i',
            $line
        )) {
            return false;
        }

        if (preg_match(
            '/(?<![a-zA-Z0-9_])(private|protected)\s*\(\s*set\s*\)/i',
            $line
        )) {
            return true;
        }

        return 1 === preg_match(
            '/(?<![a-zA-Z0-9_])\(\s*(private|protected)\s*\(\s*set\s*\)\s*\)/i',
            $line
        );
    }

    private static function offsetOfBareSetModifierInParams(string $paramsText): int
    {
        $offset = 0;
        $len = strlen($paramsText);
        while ($offset < $len) {
            $nextComma = self::findTopLevelComma($paramsText, $offset);
            $segment = false === $nextComma
                ? substr($paramsText, $offset)
                : substr($paramsText, $offset, $nextComma - $offset);
            if (self::lineHasBareSetModifierWithoutRead($segment)) {
                return $offset;
            }
            if (false === $nextComma) {
                break;
            }
            $offset = $nextComma + 1;
        }

        return -1;
    }

    private static function findTopLevelComma(string $text, int $start): int|false
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = $start; $i < $len; ++$i) {
            $char = $text[$i];
            if ('(' === $char || '[' === $char) {
                ++$depth;
            } elseif (')' === $char || ']' === $char) {
                --$depth;
            } elseif (',' === $char && 0 === $depth) {
                return $i;
            }
        }

        return false;
    }

    /**
     * Bare `private(set)` / `protected(set)` without explicit read visibility is a parse error on reference profile (#16313).
     *
     * PHP 8.4+ treats bare set modifiers as shorthand (private(set) ≡ public private(set); #16924).
     * php-src: Zend/zend_language_parser.y — property modifier grammar; Zend/zend_compile.c.
     */
    private static function rejectBareSetModifierWithoutRead(string $source): void
    {
        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            return;
        }

        $line = self::findBareSetModifierLine($source);
        if ($line > 0) {
            throw new \CompileError(self::BARE_SET_WITHOUT_READ_MESSAGE);
        }
    }

    /** Default read visibility when a bare `(set)` modifier omits an explicit read modifier (#16924). */
    private static function implicitReadPrefixForBareSetModifier(string $set): string
    {
        return 'protected' === strtolower($set) ? 'protected ' : 'public ';
    }

    /**
     * Promoted constructor parameters reject parenthesized asymmetric set modifiers on reference profile.
     *
     * php-src: Zend/zend_language_parser.y — 8.4+ accepts `public (private(set))` on promoted params (#16495);
     * reference profile still rejects like Zend 8.2 (#16436).
     */
    private static function rejectPromotedParamParenthesizedAsymmetricSet(string $source): void
    {
        if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            return;
        }

        if (!preg_match('/\b__construct\b/i', $source) || !preg_match('/\(\s*set\s*\)/i', $source)) {
            return;
        }

        $offset = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            $paramsText = self::extractBalancedParenContent($source, $openPos);
            if (null !== $paramsText && preg_match(
                '/\(\s*(?<token>private|protected|public)\s*\(\s*set\s*\)\s*\)/i',
                $paramsText,
                $tokenMatch
            )) {
                throw new \CompileError(sprintf(
                    self::PROMOTED_PARENTHESIZED_SET_MESSAGE,
                    strtolower($tokenMatch['token'])
                ));
            }
            $offset = $openPos + 1;
        }
    }

    /** Promoted constructor parameters reject duplicate set modifiers (#10237, #11656, #12088). */
    private static function rejectPromotedParamMultipleAccessModifiers(string $source): void
    {
        if (!preg_match('/\b__construct\b/i', $source) || !preg_match('/\(\s*set\s*\)/i', $source)) {
            return;
        }

        $offset = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            $paramsText = self::extractBalancedParenContent($source, $openPos);
            if (null !== $paramsText) {
                self::rejectDoubleVisibilityInPromotedParams($paramsText);
            }
            $offset = $openPos + 1;
        }
    }

    private static function extractBalancedParenContent(string $source, int $openPos): ?string
    {
        if (!isset($source[$openPos]) || '(' !== $source[$openPos]) {
            return null;
        }

        $depth = 0;
        $len = strlen($source);
        for ($i = $openPos; $i < $len; ++$i) {
            $char = $source[$i];
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char) {
                --$depth;
                if (0 === $depth) {
                    return substr($source, $openPos + 1, $i - $openPos - 1);
                }
            }
        }

        return null;
    }

    private static function rejectDoubleVisibilityInPromotedParams(string $paramsText): void
    {
        if (self::paramsViolateMultipleSetModifierRules($paramsText)) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
    }

    private static function paramsViolateMultipleSetModifierRules(string $paramsText): bool
    {
        return self::lineViolatesMultipleSetModifierRules($paramsText);
    }

    private static function lineViolatesMultipleSetModifierRules(string $line): bool
    {
        if (self::lineViolatesDuplicateSetModifierRules($line)) {
            return true;
        }

        // Reference profile: explicit read + bare set is a duplicate-modifier fatal (#12576, #13960).
        // PHP 8.4 forward profile accepts `public private(set)` and rewrites to marker form (#18820).
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            return self::lineHasExplicitReadPlusSetModifier($line);
        }

        return false;
    }

    /**
     * Zend 8.2 reference profile: explicit read + set is the same multiple-modifier fatal (#12576, #13960).
     */
    private static function lineViolatesMultipleSetModifierRulesForReferenceProfile(string $line): bool
    {
        return self::lineViolatesDuplicateSetModifierRules($line)
            || self::lineHasExplicitReadPlusSetModifier($line);
    }

    private static function lineViolatesDuplicateSetModifierRules(string $line): bool
    {
        return 1 === preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\1\s*\(\s*set\s*\)/i',
            $line
        )
            || 1 === preg_match(
                '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\(\s*\1\s*\(\s*set\s*\)\s*\)/i',
                $line
            )
            || 1 === preg_match(
                '/(?<![a-zA-Z0-9_])public\s*\(\s*set\s*\)\s*public\b/i',
                $line
            )
            || 1 === preg_match(
                '/(?<![a-zA-Z0-9_])public\s+\(\s*public\s*\(\s*set\s*\)\s*\)/i',
                $line
            );
    }

    private static function lineHasExplicitReadPlusSetModifier(string $line): bool
    {
        // Allow static/readonly between get-vis and set-vis (#29387) — still duplicate PPP on ≤8.3.
        return 1 === preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?:(?:static|readonly)\s+)*(?!\()(public|protected|private)\s*\(\s*set\s*\)/i',
            $line
        );
    }

    private static function paramsViolateMultipleSetModifierRulesForReferenceProfile(string $paramsText): bool
    {
        return self::lineViolatesMultipleSetModifierRulesForReferenceProfile($paramsText);
    }

    private static function lineViolatesStaticAsymmetricSetRules(string $line): bool
    {
        if (!preg_match('/\bstatic\b/i', $line) || !preg_match('/\(\s*set\s*\)/i', $line)) {
            return false;
        }

        $modifier = '(?:public|protected|private)';
        $setModifier = $modifier.'\s*\(\s*set\s*\)';
        $parenthesizedSet = '\(\s*'.$modifier.'\s*\(\s*set\s*\)\s*\)';
        $staticWord = '\bstatic\b';
        // Optional readonly between get-vis / set / static (Zend property_modifiers; #29387).
        $ro = '(?:readonly\s+)?';
        $patterns = [
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+(?:'.$staticWord.'\s+)?'.$ro.$setModifier.'.*'.$staticWord.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$ro.$setModifier.'\s+'.$staticWord.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$ro.$staticWord.'\s+'.$ro.$setModifier.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$ro.$staticWord.'\s+'.$ro.$parenthesizedSet.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$ro.$parenthesizedSet.'.*'.$staticWord.'/i',
            '/'.$staticWord.'\s+(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$ro.$setModifier.'/i',
            '/'.$staticWord.'\s+(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$ro.$parenthesizedSet.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+readonly\s+'.$setModifier.'\s+'.$staticWord.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+readonly\s+'.$parenthesizedSet.'.*'.$staticWord.'/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    private static function offsetOfMultipleSetModifierInParams(string $paramsText): int
    {
        $patterns = [
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\1\s*\(\s*set\s*\)/i',
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?:(?:static|readonly)\s+)*(?!\()(public|protected|private)\s*\(\s*set\s*\)/i',
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?:(?:static|readonly)\s+)*\(\s*\1\s*\(\s*set\s*\)\s*\)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $paramsText, $m, PREG_OFFSET_CAPTURE)) {
                return (int) $m[0][1];
            }
        }

        return -1;
    }

    /**
     * Static properties reject asymmetric visibility with an explicit read modifier before PHP 8.5 (#7013).
     *
     * php-src: Zend/zend_compile.c — zend_add_member_modifier(); on ≤8.4 public read with private set on
     * static is fatal ("Static property may not have asymmetric visibility", #29389). PHP 8.5 adds
     * static aviz (RFC static-aviz, #26239) — skip when {@see CompilerVersion::supportsStaticAsymmetricVisibility()}.
     */
    private static function rejectAsymmetricSetOnStaticProperty(string $source): void
    {
        if (CompilerVersion::supportsStaticAsymmetricVisibility()) {
            return;
        }
        if (!preg_match('/\bstatic\b/i', $source) || !preg_match('/\(\s*set\s*\)/i', $source)) {
            return;
        }

        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (self::lineViolatesStaticAsymmetricSetRules($line)) {
                throw new \CompileError(self::STATIC_ASYMMETRIC_VISIBILITY_MESSAGE);
            }
        });
    }

    /**
     * Run compile-time checks on single source lines only — concatenated self-host bundles and
     * docblocks must not match property-modifier patterns across lines (#1492 spine compile).
     */
    private static function eachPropertyDeclarationLine(string $source, callable $fn, string $needle = self::SET_MODIFIER_NEEDLE): void
    {
        foreach (explode("\n", $source) as $line) {
            if (!self::isInspectableAsymmetricLine($line, $needle)) {
                continue;
            }
            $fn($line);
        }
    }

    private static function isInspectableAsymmetricLine(string $line, string $needle): bool
    {
        if (false === stripos($line, $needle)) {
            return false;
        }
        $trimmed = ltrim($line);
        if ('' === $trimmed) {
            return false;
        }
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            return false;
        }
        if (preg_match('/^\s*[\x27\x22]/', $line)) {
            return false;
        }

        return true;
    }

    private static function rewriteGetModifiers(string $source): string
    {
        if (false === stripos($source, self::GET_MODIFIER_NEEDLE)) {
            return $source;
        }

        self::rejectExplicitPublicBeforeGetModifier($source);
        self::rejectExplicitPublicAfterGetModifier($source);

        return (string) preg_replace_callback(
            '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
            .'(?P<writeBefore>(?:(?:public|protected|private)\s+)?)'
            .'(?P<get>public|protected|private)\s*\(\s*get\s*\)\s*'
            .'(?P<writeAfter>(?:(?:public|protected|private)\s+)?)/i',
            static function (array $m): string {
                $get = strtolower($m['get']);
                $writeBefore = trim($m['writeBefore']);
                $writeAfter = trim($m['writeAfter']);
                if ('' !== $writeAfter) {
                    $writePrefix = $writeAfter.' ';
                } elseif ('' !== $writeBefore) {
                    $writePrefix = $writeBefore.' ';
                } else {
                    $writePrefix = 'public ';
                }

                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_GET.$get.'*/ '.$writePrefix;
            },
            $source
        );
    }

    private static function rejectExplicitPublicBeforeGetModifier(string $source): void
    {
        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (preg_match(
                '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\1\s*\(\s*get\s*\)/i',
                $line
            )) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
            if (preg_match(
                '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?!\()(public|protected|private)\s*\(\s*get\s*\)/i',
                $line
            )) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        }, self::GET_MODIFIER_NEEDLE);
    }

    private static function rejectExplicitPublicAfterGetModifier(string $source): void
    {
        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (preg_match(
                '/(?:public|protected|private)\s*\(\s*get\s*\)\s*public\b/i',
                $line
            )) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        }, self::GET_MODIFIER_NEEDLE);
    }

    public static function visibilityFromMarker(string $text): int
    {
        return self::visibilityFromPattern($text, self::MARKER_PATTERN);
    }

    public static function getVisibilityFromMarker(string $text): int
    {
        return self::visibilityFromPattern($text, self::GET_MARKER_PATTERN);
    }

    private static function visibilityFromPattern(string $text, string $pattern): int
    {
        if (!preg_match($pattern, $text, $m)) {
            return 0;
        }

        return match (strtolower($m[1])) {
            'public' => \PHPCfg\Func::FLAG_PUBLIC,
            'protected' => \PHPCfg\Func::FLAG_PROTECTED,
            'private' => \PHPCfg\Func::FLAG_PRIVATE,
            default => 0,
        };
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function extractSetVisibilityFromAttributes(array $attributes): int
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }

        foreach ($chunks as $chunk) {
            $vis = self::visibilityFromMarker($chunk);
            if (0 !== $vis) {
                return $vis;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function extractGetVisibilityFromAttributes(array $attributes): int
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }

        foreach ($chunks as $chunk) {
            $vis = self::getVisibilityFromMarker($chunk);
            if (0 !== $vis) {
                return $vis;
            }
        }

        return 0;
    }

    public static function setModifierLabel(int $setVisibilityFlags): string
    {
        if (($setVisibilityFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return 'private'.self::SET_MODIFIER_NEEDLE;
        }
        if (($setVisibilityFlags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return 'protected'.self::SET_MODIFIER_NEEDLE;
        }

        return 'public'.self::SET_MODIFIER_NEEDLE;
    }

    /**
     * php-src: Zend/zend_errors.c zend_asymmetric_visibility_property_modification_error —
     * write errors use only the set visibility (`private(set)` / `protected(set)`); get-visibility is omitted (#21526).
     *
     * $readVisibilityFlags / $explicitReadModifier retained for call-site compatibility.
     */
    public static function writeModifierLabel(int $readVisibilityFlags, int $setVisibilityFlags, bool $explicitReadModifier): string
    {
        return self::setModifierLabel($setVisibilityFlags);
    }

    /** @param array<string, mixed> $attributes */
    public static function hasExplicitReadModifierFromAttributes(array $attributes): bool
    {
        $chunks = [];
        if (isset($attributes['comments']) && is_array($attributes['comments'])) {
            foreach ($attributes['comments'] as $comment) {
                if (is_object($comment) && method_exists($comment, 'getText')) {
                    $chunks[] = $comment->getText();
                } elseif (is_string($comment)) {
                    $chunks[] = $comment;
                }
            }
        }
        if (isset($attributes['docComment']) && is_object($attributes['docComment'])
            && method_exists($attributes['docComment'], 'getText')) {
            $chunks[] = $attributes['docComment']->getText();
        }

        foreach ($chunks as $chunk) {
            if (preg_match(self::EXPLICIT_READ_MARKER_PATTERN, $chunk)) {
                return true;
            }
        }

        return false;
    }

    public static function getModifierLabel(int $getVisibilityFlags): string
    {
        if (($getVisibilityFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return 'private'.self::GET_MODIFIER_NEEDLE;
        }
        if (($getVisibilityFlags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return 'protected'.self::GET_MODIFIER_NEEDLE;
        }

        return 'public'.self::GET_MODIFIER_NEEDLE;
    }
}

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

    /** php-src: Zend/zend_compile.c — zend_add_member_modifier() duplicate PPP / PPP_SET (#6774). */
    public const MULTIPLE_MODIFIERS_MESSAGE = 'Multiple access type modifiers are not allowed';

    /**
     * @internal Marker embedded in source for PHPCfg to recover set visibility.
     */
    public const MARKER_PATTERN = '/\/\*\s*phpc-asymmetric-set:(public|protected|private)\s*\*\//i';

    /** @internal Marker for asymmetric read (get) visibility (#5059). */
    public const GET_MARKER_PATTERN = '/\/\*\s*phpc-asymmetric-get:(public|protected|private)\s*\*\//i';

    public static function containsAsymmetricVisibilitySyntax(string $source): bool
    {
        return self::hasAsymmetricVisibilitySyntax($source);
    }

    /**
     * 1-based source line of the first multiple-access-modifier violation, or 0 when none (#12576).
     *
     * Used on the Zend 8.2 reference profile where `(set)` is otherwise rejected with a generic
     * parser message — `public private(set)` must still match Zend's compile fatal.
     */
    public static function findMultipleAccessModifierLine(string $source): int
    {
        $lineNum = 0;
        foreach (explode("\n", $source) as $line) {
            ++$lineNum;
            if (!self::isInspectableAsymmetricLine($line, '(set)')) {
                continue;
            }
            if (self::lineViolatesMultipleSetModifierRulesForReferenceProfile($line)) {
                return $lineNum;
            }
            if (self::lineViolatesStaticAsymmetricSetRules($line)) {
                return $lineNum;
            }
        }

        if (!preg_match('/\b__construct\b/i', $source) || !preg_match('/\(\s*set\s*\)/i', $source)) {
            return 0;
        }

        $offset = 0;
        while (preg_match('/\bfunction\s+__construct\s*\(/i', $source, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openPos = $m[0][1] + strlen($m[0][0]) - 1;
            $paramsText = self::extractBalancedParenContent($source, $openPos);
            if (null !== $paramsText && self::paramsViolateMultipleSetModifierRulesForReferenceProfile($paramsText)) {
                $constructLine = substr_count(substr($source, 0, $openPos), "\n") + 1;
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
        return false !== stripos($source, '(set)')
            || false !== stripos($source, '(get)');
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
        foreach ($tokens as $token) {
            if (is_string($token)) {
                $masked .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (T_COMMENT === $id || T_DOC_COMMENT === $id || T_CONSTANT_ENCAPSED_STRING === $id) {
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

        self::rejectExplicitPublicBeforeSetModifier($source);
        self::rejectExplicitPublicAfterSetModifier($source);
        self::rejectPromotedParamMultipleAccessModifiers($source);
        self::rejectAsymmetricSetOnStaticProperty($source);

        $source = (string) preg_replace_callback(
            '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
            .'(?P<readBefore>(?:(?:public|protected|private)\s+)?)'
            .'(?P<static>(?:static\s+)?)'
            .'\(\s*(?P<set>public|protected|private)\s*\(\s*set\s*\)\s*\)\s*/i',
            static function (array $m): string {
                $set = strtolower($m['set']);
                $readBefore = trim($m['readBefore']);
                if ('' !== $readBefore) {
                    $readPrefix = $readBefore.' ';
                } else {
                    $readPrefix = 'public ';
                }

                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_SET.$set.'*/ '.$readPrefix.$m['static'];
            },
            $source
        );

        return (string) preg_replace_callback(
            '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
            .'(?P<readBefore>(?:(?:public|protected|private)\s+)?)'
            .'(?P<set>public|protected|private)\s*\(\s*set\s*\)\s*'
            .'(?P<readAfter>(?:(?:public|protected|private)\s+)?)/i',
            static function (array $m): string {
                $set = strtolower($m['set']);
                $readBefore = trim($m['readBefore']);
                $readAfter = trim($m['readAfter']);
                if ('' !== $readAfter) {
                    $readPrefix = $readAfter.' ';
                } elseif ('' !== $readBefore) {
                    $readPrefix = $readBefore.' ';
                } else {
                    $readPrefix = 'public ';
                }

                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_SET.$set.'*/ '.$readPrefix;
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

    /** Explicit read `public` after `public(set)` duplicates implicit public read (#6589, #6774). */
    private static function rejectExplicitPublicAfterSetModifier(string $source): void
    {
        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (self::lineViolatesMultipleSetModifierRules($line)) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        });
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
        return 1 === preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\1\s*\(\s*set\s*\)/i',
            $line
        )
            || 1 === preg_match(
                '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\(\s*(public|protected|private)\s*\(\s*set\s*\)\s*\)/i',
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

    /**
     * Zend 8.2 reference profile: any explicit read plus `(set)` is fatal (#12576).
     *
     * PHP 8.4 allows distinct read+set pairs such as `public private(set)` (#11868, #12781).
     */
    private static function lineViolatesMultipleSetModifierRulesForReferenceProfile(string $line): bool
    {
        return self::lineViolatesMultipleSetModifierRules($line)
            || self::lineHasExplicitReadPlusSetModifier($line);
    }

    private static function lineHasExplicitReadPlusSetModifier(string $line): bool
    {
        return 1 === preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?!\()(public|protected|private)\s*\(\s*set\s*\)/i',
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
        $patterns = [
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+(?:'.$staticWord.'\s+)?'.$setModifier.'.*'.$staticWord.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$setModifier.'\s+'.$staticWord.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$staticWord.'\s+'.$setModifier.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$staticWord.'\s+'.$parenthesizedSet.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$parenthesizedSet.'.*'.$staticWord.'/i',
            '/'.$staticWord.'\s+(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$setModifier.'/i',
            '/'.$staticWord.'\s+(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$parenthesizedSet.'/i',
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
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(?!\()(public|protected|private)\s*\(\s*set\s*\)/i',
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\(\s*(public|protected|private)\s*\(\s*set\s*\)\s*\)/i',
        ]; // third pattern: reference profile + parenthesized dual modifiers
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $paramsText, $m, PREG_OFFSET_CAPTURE)) {
                return (int) $m[0][1];
            }
        }

        return -1;
    }

    /**
     * Static properties do not support asymmetric visibility with an explicit read modifier (#7013).
     *
     * php-src: Zend/zend_compile.c — zend_add_member_modifier(); `public private(set) static` is fatal
     * (`Multiple access type modifiers are not allowed`). `private(set) static` alone remains valid (#6769).
     */
    private static function rejectAsymmetricSetOnStaticProperty(string $source): void
    {
        if (!preg_match('/\bstatic\b/i', $source) || !preg_match('/\(\s*set\s*\)/i', $source)) {
            return;
        }

        self::eachPropertyDeclarationLine($source, static function (string $line): void {
            if (self::lineViolatesStaticAsymmetricSetRules($line)) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        });
    }

    /**
     * Run compile-time checks on single source lines only — concatenated self-host bundles and
     * docblocks must not match property-modifier patterns across lines (#1492 spine compile).
     */
    private static function eachPropertyDeclarationLine(string $source, callable $fn, string $needle = '(set)'): void
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
        if (false === stripos($source, '(get)')) {
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
        }, '(get)');
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
        }, '(get)');
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
            return 'private(set)';
        }
        if (($setVisibilityFlags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return 'protected(set)';
        }

        return 'public(set)';
    }

    public static function getModifierLabel(int $getVisibilityFlags): string
    {
        if (($getVisibilityFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return 'private(get)';
        }
        if (($getVisibilityFlags & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return 'protected(get)';
        }

        return 'public(get)';
    }
}

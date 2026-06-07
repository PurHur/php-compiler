<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

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

    public static function rewrite(string $source): string
    {
        $source = self::rewriteSetModifiers($source);

        return self::rewriteGetModifiers($source);
    }

    private static function rewriteSetModifiers(string $source): string
    {
        if (
            false === stripos($source, '(set)')
            && false === stripos($source, 'public(set)')
            && false === stripos($source, 'protected(set)')
            && false === stripos($source, 'private(set)')
        ) {
            return $source;
        }

        self::rejectExplicitPublicBeforeSetModifier($source);
        self::rejectExplicitReadBeforeSetModifier($source);
        self::rejectExplicitPublicAfterSetModifier($source);
        self::rejectExplicitReadBeforeParenthesizedSetModifier($source);
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
     * Duplicate read/set visibility on the same axis is a compile fatal (#6774, #6861).
     *
     * php-src: Zend/zend_compile.c — zend_add_member_modifier(); `public public(set)` duplicates
     * the same modifier.
     */
    private static function rejectExplicitPublicBeforeSetModifier(string $source): void
    {
        if (preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\1\s*\(\s*set\s*\)/i',
            $source
        )) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
    }

    /**
     * Explicit read before X(set) duplicates PPP / PPP_SET (#7388, #6589 regression).
     *
     * php-src: Zend/zend_compile.c — zend_add_member_modifier(); `public private(set)` is fatal.
     * Valid forms use `X(set)` alone or `X(set) READ` after the set modifier.
     */
    private static function rejectExplicitReadBeforeSetModifier(string $source): void
    {
        if (preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+(public|protected|private)\s*\(\s*set\s*\)/i',
            $source
        )) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
    }

    /** Explicit read `public` after `(set)` duplicates implicit public read (#6589, #6774). */
    private static function rejectExplicitPublicAfterSetModifier(string $source): void
    {
        if (preg_match(
            '/(?:public|protected|private)\s*\(\s*set\s*\)\s*public\b/i',
            $source
        )) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
    }

    /**
     * Duplicate read/set on parenthesized X(set) is a compile fatal (#6897, #7308).
     *
     * php-src: `public (public(set))` is fatal; `public (private(set))` is valid (RFC asymmetric-visibility-v2).
     */
    private static function rejectExplicitReadBeforeParenthesizedSetModifier(string $source): void
    {
        if (preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\(\s*\1\s*\(\s*set\s*\)\s*\)/i',
            $source
        )) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
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

        $modifier = '(?:public|protected|private)';
        $setModifier = $modifier.'\s*\(\s*set\s*\)';
        $parenthesizedSet = '\(\s*'.$modifier.'\s*\(\s*set\s*\)\s*\)';
        $staticWord = '\bstatic\b';
        $patterns = [
            // read + set(set) … static (any spacing)
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+(?:'.$staticWord.'\s+)?'.$setModifier.'[^;{]*'.$staticWord.'/i',
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$setModifier.'\s+'.$staticWord.'/i',
            // read + static + set(set)
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$staticWord.'\s+'.$setModifier.'/i',
            // read + static + (set(set))
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$staticWord.'\s+'.$parenthesizedSet.'/i',
            // read + (set(set)) … static
            '/(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$parenthesizedSet.'[^;{]*'.$staticWord.'/i',
            // static + read + set(set)
            '/'.$staticWord.'\s+(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$setModifier.'/i',
            // static + read + (set(set))
            '/'.$staticWord.'\s+(?<![a-zA-Z0-9_])'.$modifier.'\s+'.$parenthesizedSet.'/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source)) {
                throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
            }
        }
    }

    private static function rewriteGetModifiers(string $source): string
    {
        if (
            false === stripos($source, '(get)')
            && false === stripos($source, 'public(get)')
            && false === stripos($source, 'protected(get)')
            && false === stripos($source, 'private(get)')
        ) {
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
        if (preg_match(
            '/(?<![a-zA-Z0-9_])(public|protected|private)\s+\1\s*\(\s*get\s*\)/i',
            $source
        )) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
    }

    private static function rejectExplicitPublicAfterGetModifier(string $source): void
    {
        if (preg_match(
            '/(?:public|protected|private)\s*\(\s*get\s*\)\s*public\b/i',
            $source
        )) {
            throw new \CompileError(self::MULTIPLE_MODIFIERS_MESSAGE);
        }
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

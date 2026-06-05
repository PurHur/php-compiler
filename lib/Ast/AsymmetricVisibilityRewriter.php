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

        return (string) preg_replace_callback(
            '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
            .'(?P<read>(?:(?:public|protected|private)\s+)?)'
            .'(?P<set>public|protected|private)\s*\(\s*set\s*\)\s*/i',
            static function (array $m): string {
                $set = strtolower($m['set']);
                $read = trim($m['read']);
                $readPrefix = '' !== $read ? $read.' ' : 'public ';

                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_SET.$set.'*/ '.$readPrefix;
            },
            $source
        );
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

        return (string) preg_replace_callback(
            '/(?P<prefix>(?:\/\*(?:[^*]|\*(?!\/))*\*\/\s*)*)(?P<attrs>(?:#\[[^\]]*\]\s*)*)'
            .'(?P<write>(?:(?:public|protected|private)\s+)?)'
            .'(?P<get>public|protected|private)\s*\(\s*get\s*\)\s*/i',
            static function (array $m): string {
                $get = strtolower($m['get']);
                $write = trim($m['write']);
                $writePrefix = '' !== $write ? $write.' ' : 'public ';

                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX_GET.$get.'*/ '.$writePrefix;
            },
            $source
        );
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

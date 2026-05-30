<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

/**
 * Rewrite PHP 8.4 asymmetric property visibility for nikic/php-parser 4.x (#3165).
 *
 * Transforms `public private(set) Type $prop` into a parseable form plus an internal marker
 * comment consumed by PHPCfg (php-src: Zend/zend_compile.c ZEND_ACC_*_SET flags).
 */
final class AsymmetricVisibilityRewriter
{
    private const MARKER_PREFIX = 'phpc-asymmetric-set:';

    /**
     * @internal Marker embedded in source for PHPCfg to recover set visibility.
     */
    public const MARKER_PATTERN = '/\/\*\s*phpc-asymmetric-set:(public|protected|private)\s*\*\//i';

    public static function rewrite(string $source): string
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

                return $m['prefix'].$m['attrs'].'/*'.self::MARKER_PREFIX.$set.'*/ '.$readPrefix;
            },
            $source
        );
    }

    public static function visibilityFromMarker(string $text): int
    {
        if (!preg_match(self::MARKER_PATTERN, $text, $m)) {
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
}

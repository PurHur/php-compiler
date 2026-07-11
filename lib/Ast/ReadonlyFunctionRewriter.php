<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Rewrite PHP 8.4 `readonly function` / `readonly fn` for nikic/php-parser 4.x (#17657).
 *
 * php-src: Zend/zend_compile.c ZEND_ACC_READONLY_FUNCTION; zend_ast.c ZEND_AST_FUNC_DECL.
 */
final class ReadonlyFunctionRewriter
{
    private const MARKER = 'phpc-readonly-function';

    /** @internal Marker embedded in source for AST attribute recovery. */
    public const MARKER_PATTERN = '/\/\*\s*phpc-readonly-function\s*\*\//i';

    public static function containsReadonlyFunctionSyntax(string $source): bool
    {
        return (bool) preg_match('/\breadonly\s+(?:function|fn)\b/i', $source);
    }

    public static function rewrite(string $source): string
    {
        if (!CompilerVersion::supportsReadonlyFunction()) {
            return $source;
        }
        if (!self::containsReadonlyFunctionSyntax($source)) {
            return $source;
        }

        return (string) preg_replace_callback(
            '/\breadonly\s+(function|fn)\b/i',
            static fn (array $m): string => '/*'.self::MARKER.'*/ '.$m[1],
            $source
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function isReadonlyFromAttributes(array $attributes): bool
    {
        foreach (self::commentChunksFromAttributes($attributes) as $chunk) {
            if (preg_match(self::MARKER_PATTERN, $chunk)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<string>
     */
    private static function commentChunksFromAttributes(array $attributes): array
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

        return $chunks;
    }
}

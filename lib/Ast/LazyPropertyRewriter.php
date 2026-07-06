<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PHPCompiler\CompilerVersion;

/**
 * Rewrite PHP 8.4 `lazy` property modifier for nikic/php-parser 4.x (#16813).
 *
 * php-src: Zend/zend_compile.c ZEND_ACC_LAZY; zend_lazy_objects.c deferred initializer.
 */
final class LazyPropertyRewriter
{
  private const MARKER = 'phpc-lazy-property';

  /** @internal Marker embedded in source for PHPCfg attribute recovery. */
  public const MARKER_PATTERN = '/\/\*\s*phpc-lazy-property\s*\*\//i';

  public static function containsLazyPropertySyntax(string $source): bool
  {
    return (bool) preg_match(
      '/\b(public|protected|private)\s+(?:(?:static|readonly|final)\s+)*lazy\b/i',
      $source
    );
  }

  public static function rewrite(string $source): string
  {
    if (!CompilerVersion::supportsLazyPropertyModifier()) {
      return $source;
    }
    if (!self::containsLazyPropertySyntax($source)) {
      return $source;
    }

    return (string) preg_replace_callback(
      '/\b(public|protected|private)\s+((?:(?:static|readonly|final)\s+)*)lazy\s+((?:(?:static|readonly|final)\s+)*)/i',
      // Leading marker so nikic attaches comments to Stmt\Property (#16953, asymmetric-visibility pattern).
      static fn (array $m): string => '/*'.self::MARKER.'*/ '.$m[1].' '.$m[2].$m[3],
      $source
    );
  }

  /**
   * @param array<string, mixed> $attributes
   */
  public static function isLazyFromAttributes(array $attributes): bool
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

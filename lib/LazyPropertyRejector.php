<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\LazyPropertyRewriter;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject PHP 8.4 `lazy` property modifier on the Zend 8.2 reference profile (#16813).
 *
 * php-src: Zend/zend_language_parser.y T_LAZY gated to PHP 8.4+.
 */
final class LazyPropertyRejector
{
  /** Zend 8.2 profile message for `public lazy Type $prop` (#16813). */
  public const PARSE_MESSAGE = 'Syntax error, unexpected T_STRING, expecting T_VARIABLE';

  public static function reject(string $code, string $filename = 'unknown'): string
  {
    if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
      return $code;
    }
    if (CompilerVersion::supportsLazyPropertyModifier()) {
      return $code;
    }
    if (!LazyPropertyRewriter::containsLazyPropertySyntax($code)) {
      return $code;
    }

    $line = self::findLazyModifierLine($code);
    if ($line <= 0) {
      return $code;
    }

    throw new CompileFatal($filename, $line, self::PARSE_MESSAGE);
  }

  private static function findLazyModifierLine(string $source): int
  {
    $lineNum = 0;
    foreach (explode("\n", $source) as $line) {
      ++$lineNum;
      if (preg_match('/\b(public|protected|private)\s+(?:(?:static|readonly|final)\s+)*lazy\b/i', $line)) {
        return $lineNum;
      }
    }

    return 0;
  }
}

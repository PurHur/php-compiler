<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PhpParser\Node;
use PhpParser\Node\Expr\Ternary;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/**
 * Reject unparenthesized nested ternaries like Zend 7.4+ (#20737).
 *
 * nikic/php-parser still accepts left-associative nesting; php-src fatals at parse time.
 * Pure elvis chaining (`a ?: b ?: c`) remains legal.
 *
 * php-src: Zend/zend_language_parser.y — ternary / T_ERROR for nested `?:` without parentheses
 */
final class NestedTernaryRejector
{
    public const MSG_TERNARY_TERNARY = 'Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`';

    public const MSG_TERNARY_ELVIS = 'Unparenthesized `a ? b : c ?: d` is not supported. Use either `(a ? b : c) ?: d` or `a ? b : (c ?: d)`';

    public const MSG_ELVIS_TERNARY = 'Unparenthesized `a ?: b ? c : d` is not supported. Use either `(a ?: b) ? c : d` or `a ?: (b ? c : d)`';

    public static function reject(string $code, string $filename = 'unknown'): string
    {
        // Ternary/elvis `?` is a single-char token; nullable/`?->`/`??` use other tokens.
        if (self::ternaryQuestionCount($code) < 2) {
            return $code;
        }

        $lexer = new \PhpParser\Lexer\Emulative([
            'usedAttributes' => [
                'comments',
                'startLine',
                'endLine',
                'startFilePos',
                'endFilePos',
            ],
        ]);
        $parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7, $lexer);
        try {
            $ast = $parser->parse($code);
        } catch (\PhpParser\Error $e) {
            // Let the main parse pipeline surface syntax errors.
            return $code;
        }
        if (null === $ast) {
            return $code;
        }

        $visitor = new class ($code, $filename) extends NodeVisitorAbstract {
            public function __construct(
                private readonly string $code,
                private readonly string $filename,
            ) {
            }

            public function enterNode(Node $node)
            {
                if (!$node instanceof Ternary || !$node->cond instanceof Ternary) {
                    return null;
                }
                if (self::isParenthesized($this->code, $node->cond)) {
                    return null;
                }

                $outerElvis = null === $node->if;
                $innerElvis = null === $node->cond->if;
                if ($outerElvis && $innerElvis) {
                    // Pure elvis chaining is legal since PHP 7.4.
                    return null;
                }

                if ($outerElvis && !$innerElvis) {
                    $message = NestedTernaryRejector::MSG_TERNARY_ELVIS;
                } elseif (!$outerElvis && $innerElvis) {
                    $message = NestedTernaryRejector::MSG_ELVIS_TERNARY;
                } else {
                    $message = NestedTernaryRejector::MSG_TERNARY_TERNARY;
                }

                throw new CompileFatal(
                    $this->filename,
                    max(1, $node->getStartLine()),
                    $message
                );
            }

            private static function isParenthesized(string $code, Ternary $inner): bool
            {
                $start = $inner->getAttribute('startFilePos');
                $end = $inner->getAttribute('endFilePos');
                if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start) {
                    return false;
                }

                $i = $start - 1;
                while ($i >= 0 && self::isSpace($code[$i])) {
                    --$i;
                }
                if ($i < 0 || '(' !== $code[$i]) {
                    return false;
                }

                $len = strlen($code);
                $j = $end + 1;
                while ($j < $len && self::isSpace($code[$j])) {
                    ++$j;
                }

                return $j < $len && ')' === $code[$j];
            }

            private static function isSpace(string $ch): bool
            {
                return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $code;
    }

    private static function ternaryQuestionCount(string $code): int
    {
        if (!str_contains($code, '?')) {
            return 0;
        }
        if (!\function_exists('token_get_all')) {
            return substr_count($code, '?');
        }
        $count = 0;
        foreach (token_get_all($code) as $token) {
            if (\is_string($token) && '?' === $token) {
                ++$count;
            }
        }

        return $count;
    }
}

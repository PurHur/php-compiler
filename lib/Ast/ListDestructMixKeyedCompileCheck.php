<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Reject list()/[] destructuring that mixes keyed and unkeyed slots (#14879).
 *
 * php-cfg lowers both forms to integer dim fetches and cannot distinguish
 * `list(0 => $x, $y)` from invalid patterns — validate on php-parser AST.
 * php-src: Zend/zend_compile.c — zend_compile_list_assign()
 */
final class ListDestructMixKeyedCompileCheck extends NodeVisitorAbstract
{
    public const MESSAGE = 'Cannot mix keyed and unkeyed array entries in assignments';

    public function enterNode(Node $node)
    {
        if ($node instanceof Assign || $node instanceof AssignRef) {
            $this->checkDestructPattern($node->var, $node);
        }
        if ($node instanceof Foreach_ && ($node->valueVar instanceof List_ || $node->valueVar instanceof Array_)) {
            $this->checkDestructPattern($node->valueVar, $node);
        }

        return null;
    }

    private function checkDestructPattern(Expr $pattern, Node $context): void
    {
        if (!$pattern instanceof List_ && !$pattern instanceof Array_) {
            return;
        }

        if ($this->patternMixesKeyedAndUnkeyed($pattern->items)) {
            $this->fatal($context);
        }
    }

    /**
     * @param array<int, Expr\ArrayItem|null> $items
     */
    private function patternMixesKeyedAndUnkeyed(array $items): bool
    {
        $hasKeyed = false;
        $hasUnkeyed = false;
        foreach ($items as $item) {
            if (null === $item) {
                continue;
            }
            if ($item->unpack) {
                continue;
            }
            $value = $item->value;
            if ($value instanceof List_ || $value instanceof Array_) {
                if ($this->patternMixesKeyedAndUnkeyed($value->items)) {
                    return true;
                }
            }
            if (null !== $item->key) {
                $hasKeyed = true;
            } else {
                $hasUnkeyed = true;
            }
            if ($hasKeyed && $hasUnkeyed) {
                return true;
            }
        }

        return false;
    }

    private function fatal(Node $node): void
    {
        $file = $node->getAttribute('fileName', 'unknown');
        if (!is_string($file) || '' === $file) {
            $file = 'unknown';
        }

        throw new CompileFatal(
            $file,
            max(1, $node->getStartLine()),
            self::MESSAGE
        );
    }
}

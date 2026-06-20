<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

/**
 * Mark user callables that contain `yield` / `yield from` in source (#10333, #3350).
 *
 * php-cfg may DCE unreachable yield after return; Zend still treats the callable as a
 * generator when yield appears in source (zend_compile.c / zend_generators.c).
 */
final class GeneratorYieldSourceMarker extends NodeVisitorAbstract
{
    public const ATTRIBUTE = 'compilerSourceHasYield';

    /** @var list<Node\FunctionLike> */
    private array $stack = [];

    private bool $currentHasYield = false;

    public function enterNode(Node $node)
    {
        if ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure || $node instanceof ArrowFunction) {
            $this->stack[] = $node;
            $this->currentHasYield = false;

            return null;
        }
        if ([] !== $this->stack && ($node instanceof Yield_ || $node instanceof YieldFrom)) {
            $this->currentHasYield = true;
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ([] === $this->stack || end($this->stack) !== $node) {
            return null;
        }
        array_pop($this->stack);
        if ($this->currentHasYield) {
            $node->setAttribute(self::ATTRIBUTE, true);
        }
        $this->currentHasYield = false;

        return null;
    }
}

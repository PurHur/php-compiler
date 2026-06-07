<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitor;

/**
 * Attach readonly-function metadata from {@see ReadonlyFunctionDesugar} (#7428).
 */
final class ReadonlyFunctionAnnotator implements NodeVisitor
{
    /** @var list<int> */
    private array $readonlyLines = [];

    /**
     * @param list<int> $readonlyLines
     */
    public function setReadonlyLines(array $readonlyLines): void
    {
        $this->readonlyLines = $readonlyLines;
    }

    public function beforeTraverse(array $nodes)
    {
        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Function_ && !$node instanceof Closure) {
            return null;
        }
        $line = $node->getStartLine();
        if (!\in_array($line, $this->readonlyLines, true)) {
            return null;
        }
        $node->setAttribute('compilerReadonlyFunction', true);

        return null;
    }

    public function leaveNode(Node $node)
    {
        return null;
    }

    public function afterTraverse(array $nodes)
    {
        return null;
    }
}

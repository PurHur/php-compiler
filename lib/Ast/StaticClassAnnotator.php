<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitor;

/**
 * Attach static-class flags from {@see StaticClassPreprocessor} onto parsed AST nodes (#6929).
 */
final class StaticClassAnnotator implements NodeVisitor
{
    /** @var array<int, true> declaration start line => static class */
    private array $staticLines = [];

    /**
     * @param array<int, true> $staticLines
     */
    public function setStaticLines(array $staticLines): void
    {
        $this->staticLines = $staticLines;
    }

    public function beforeTraverse(array $nodes)
    {
        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Class_) {
            return null;
        }
        $line = $node->getStartLine();
        if (!isset($this->staticLines[$line])) {
            return null;
        }
        $node->flags |= Class_::MODIFIER_STATIC;

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

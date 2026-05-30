<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeVisitor;

/**
 * Attach sealed metadata from {@see SealedClassPreprocessor} onto parsed AST nodes (#3322).
 */
final class SealedClassAnnotator implements NodeVisitor
{
    /** @var array<int, list<string>> declaration start line => permitted children (lowercase) */
    private array $permitsByLine = [];

    /**
     * @param array<int, list<string>> $permitsByLine
     */
    public function setPermitsByLine(array $permitsByLine): void
    {
        $this->permitsByLine = $permitsByLine;
    }

    public function beforeTraverse(array $nodes)
    {
        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Class_ && !$node instanceof Interface_) {
            return null;
        }
        $line = $node->getStartLine();
        if (!isset($this->permitsByLine[$line])) {
            return null;
        }
        $node->setAttribute('compilerSealed', true);
        $node->setAttribute('compilerSealedPermits', $this->permitsByLine[$line]);

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

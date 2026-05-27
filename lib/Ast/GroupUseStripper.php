<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeVisitor;

/**
 * Remove Stmt_GroupUse after NameResolver registered aliases.
 *
 * PHPCfg ignores Stmt_Use but does not know GroupUse (#2443).
 *
 * Implements {@see NodeVisitor} instead of extending {@see NodeVisitorAbstract}
 * so self-host lint/AOT lowering does not need vendor parent class wiring (#2634).
 */
final class GroupUseStripper implements NodeVisitor
{
    public function beforeTraverse(array $nodes)
    {
        return null;
    }

    public function enterNode(Node $node)
    {
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof GroupUse) {
            return [];
        }

        return null;
    }

    public function afterTraverse(array $nodes)
    {
        return null;
    }
}

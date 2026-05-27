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
 * Implements NodeVisitor inline so static compile lint does not need the vendor-only
 * NodeVisitorAbstract baseline class from nikic/php-parser (#2634).
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

    /** Drop group-use declarations once NameResolver owns the aliases (#2443). */
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

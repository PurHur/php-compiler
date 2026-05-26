<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeVisitorAbstract;

/**
 * Remove Stmt_GroupUse after NameResolver registered aliases.
 *
 * PHPCfg ignores Stmt_Use but does not know GroupUse (#2443).
 */
final class GroupUseStripper extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if ($node instanceof GroupUse) {
            return [];
        }

        return null;
    }
}

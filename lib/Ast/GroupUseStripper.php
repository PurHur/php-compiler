<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\NodeVisitor;

/**
 * Bundle-local PhpParser visitor base (parity with PhpParser\NodeVisitorAbstract).
 *
 * Nikic's NodeVisitorAbstract lives under vendor/, which PHPTypes does not analyze in
 * self-host bundles, so subclasses would emit "Could not find parent" during AOT lint
 * (#2634). PhpParser traversers accept any {@see NodeVisitor} implementation.
 *
 * @codeCoverageIgnore
 */
abstract class AstNodeVisitorAbstract implements NodeVisitor
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
        return null;
    }

    public function afterTraverse(array $nodes)
    {
        return null;
    }
}

/**
 * Remove Stmt_GroupUse after NameResolver registered aliases.
 *
 * PHPCfg ignores Stmt_Use but does not know GroupUse (#2443).
 */
final class GroupUseStripper extends AstNodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if ($node instanceof GroupUse) {
            return [];
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\NodeVisitorAbstract;

/**
 * Mark Catch_ nodes rewritten from intersection types (#28205).
 */
final class CatchIntersectionAttacher extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if (!$node instanceof Catch_) {
            return null;
        }
        if (!CatchIntersectionSupport::takeNextIntersectionFlag()) {
            return null;
        }
        $node->setAttribute(CatchIntersectionSupport::ATTRIBUTE, true);

        return null;
    }
}

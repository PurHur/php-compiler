<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeVisitorAbstract;

/**
 * Attach extracted try/catch/else bodies to AST TryCatch nodes (#15817).
 */
final class TryCatchElseAttacher extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        if (!$node instanceof TryCatch) {
            return null;
        }
        $elseSource = TryCatchElseSupport::takeNextElseSource();
        if (null === $elseSource) {
            return null;
        }
        $node->setAttribute(TryCatchElseSupport::ATTRIBUTE, $elseSource);

        return null;
    }
}

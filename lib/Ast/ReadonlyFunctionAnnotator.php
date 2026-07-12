<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitor;

/**
 * Attach readonly-function metadata from {@see ReadonlyFunctionRewriter} onto parsed AST nodes (#17657).
 */
final class ReadonlyFunctionAnnotator implements NodeVisitor
{
    public function beforeTraverse(array $nodes)
    {
        return null;
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Function_ && !$node instanceof Closure && !$node instanceof ArrowFunction) {
            return null;
        }
        if (!ReadonlyFunctionRewriter::isReadonlyFromAttributes($node->getAttributes())) {
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

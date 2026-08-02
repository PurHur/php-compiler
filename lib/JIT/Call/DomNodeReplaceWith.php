<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeChildNodeMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::replaceWith() — user-script AOT ChildNode (#26752). */
final class DomNodeReplaceWith implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_replacewith_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::replaceWith() called without $this');
        }

        return DomNodeChildNodeMutationRuntime::invokeReplaceWith(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }
}

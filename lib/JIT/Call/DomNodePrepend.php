<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::prepend() — user-script AOT (#18951). */
final class DomNodePrepend implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_prepend_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::prepend() called without $this');
        }

        return DomNodeLiveMutationRuntime::invokePrepend(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }
}

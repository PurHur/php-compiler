<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeChildNodeMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::before() — user-script AOT ChildNode (#26752). */
final class DomNodeBefore implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_before_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::before() called without $this');
        }

        return DomNodeChildNodeMutationRuntime::invokeBefore(
            $context,
            \count($args) - 1,
            $args[0],
            ...\array_slice($args, 1)
        );
    }
}

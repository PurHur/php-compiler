<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomNodeChildNodeMutationRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::remove() — user-script AOT ChildNode (#26752). */
final class DomNodeChildRemove implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_child_remove_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::remove() called without $this');
        }

        return DomNodeChildNodeMutationRuntime::invokeRemove($context, $args[0]);
    }
}

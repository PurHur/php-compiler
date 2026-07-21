<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::isSameNode() — user-script AOT pointer identity (#21687). */
final class DomNodeIsSameNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_is_same_node_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::isSameNode() expects receiver and other');
        }

        return DomLivingApiRuntime::invokeIsSameNode($context, $args[0], $args[1]);
    }
}

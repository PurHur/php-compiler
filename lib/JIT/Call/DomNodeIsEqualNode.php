<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::isEqualNode() — user-script AOT (#19507). */
final class DomNodeIsEqualNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_is_equal_node_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::isEqualNode() expects receiver and other');
        }

        $result = DomLivingApiRuntime::invokeIsEqualNode($context, $args[0], $args[1]);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_is_equal_node_post_invoke');

        return $result;
    }
}

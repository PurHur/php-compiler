<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::contains() — user-script AOT (#19507). */
final class DomNodeContains implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_contains_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::contains() expects receiver and other');
        }

        $result = DomLivingApiRuntime::invokeContains($context, $args[0], $args[1]);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_contains_post_invoke');

        return $result;
    }
}

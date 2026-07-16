<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMNode::getRootNode() — user-script AOT (#19507). */
final class DomNodeGetRootNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_get_root_node_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMNode::getRootNode() called without $this');
        }

        return DomLivingApiRuntime::invokeGetRootNode($context, $args[0]);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::toggleAttribute() — user-script AOT (#19507). */
final class DomElementToggleAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_toggle_attribute_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::toggleAttribute() expects receiver and name');
        }

        return DomLivingApiRuntime::invokeToggleAttribute(
            $context,
            $args[0],
            $args[1],
            $args[2] ?? null
        );
    }
}

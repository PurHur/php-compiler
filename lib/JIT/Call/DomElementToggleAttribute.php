<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
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
        if (\count($args) < 2) {
            throw new \LogicException('DOMElement::toggleAttribute() expects receiver and name');
        }

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            BasicBlockHelper::branchToFreshContinue($context, 'dom_toggle_attribute_invoke');
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_toggle_attribute_invoke_cont');
        }

        $result = DomLivingApiRuntime::invokeToggleAttribute(
            $context,
            $args[0],
            $args[1],
            $args[2] ?? null
        );

        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            $mainCont = BasicBlockHelper::append($context, 'main_cont_after_dom_toggle_attribute');
            $context->builder->branch($mainCont);
            $context->builder->positionAtEnd($mainCont);
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptAttributeCacheLlvm;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Dom\Element::hasAttribute() — thin user-script AOT live Attr cache (#27108).
 *
 * Returns boxed int 1/0 (truthy) to avoid bool-box ABI gaps in thin AOT.
 */
final class DomElementHasAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_hasattr_invoke_cont');
        if (\count($args) < 2) {
            throw new \LogicException('Dom\\Element::hasAttribute() expects receiver and name');
        }
        $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $present = null !== $nameLit
            && DomUserScriptAttributeCacheLlvm::hasPresentLiteral('', $nameLit);

        $slot = JitValueBox::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $i64->constInt($present ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}

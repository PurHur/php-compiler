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
        $i64 = $context->getTypeFromString('int64');
        $slot = JitValueBox::alloc($context);
        if (null === $nameLit) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $slot),
                $i64->constInt(0, false)
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }
        $attr = DomUserScriptAttributeCacheLlvm::lookupLiteral($context, '', $nameLit);
        $objPtr = $context->getTypeFromString('__object__*');
        $isPresent = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $attr,
            $objPtr->constNull()
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $context->builder->zExt($isPresent, $i64)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}

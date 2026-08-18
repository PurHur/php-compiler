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
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * DOMElement::hasAttributeNS() — user-script AOT live Attr cache (#32398).
 *
 * Returns boxed int 1/0 (truthy) to avoid bool-box ABI gaps in thin AOT.
 */
final class DomElementHasAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_hasattrns_invoke_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::hasAttributeNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $nsLit && (Variable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            $nsLit = '';
        }
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        $present = null !== $nsLit && null !== $localLit
            && DomUserScriptAttributeCacheLlvm::hasPresentLiteral($nsLit, $localLit);

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

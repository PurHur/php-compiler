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
 * Dom\Element::getAttributeNS() — thin user-script AOT live Attr cache (#27108).
 */
final class DomElementGetAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattrns_invoke_cont');
        // Legacy DOMElement + living Dom\Element share this Call (#31011).
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMElement::getAttributeNS',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $nsLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $nsLit && (Variable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant)) {
            $nsLit = '';
        }
        $localLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $nsLit || null === $localLit
            || !DomUserScriptAttributeCacheLlvm::hasPresentLiteral($nsLit, $localLit)
        ) {
            return self::boxConstantString($context, '');
        }

        return self::boxConstantString(
            $context,
            DomUserScriptAttributeCacheLlvm::literalValue($nsLit, $localLit) ?? ''
        );
    }

    private static function boxConstantString(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}

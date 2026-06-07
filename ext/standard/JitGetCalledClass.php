<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func;
use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LateStaticBindingHelper;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for get_called_class() (issue #3218, #6853).
 *
 * php-src: ext/standard/basic_functions.c — php_get_called_class()
 */
final class JitGetCalledClass
{
    public static function invoke(Context $context): Value
    {
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block || null === $block->func || null === $block->func->class) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise($context, 'get_called_class() must be called from within a class');
            $context->builder->call($context->lookupFunction('abort'));

            return self::emptyStringBox($context);
        }

        $isStatic = 0 !== (($block->func->flags ?? 0) & Func::FLAG_STATIC);
        if (!$isStatic) {
            $thisVar = $context->variableForScopedName('this');
            if (null !== $thisVar) {
                return self::boxString($context, ReflectionBuiltinHelper::getClassName($context, $thisVar));
            }
        }

        $objectType = $context->type->object;
        if (LateStaticBindingHelper::useRuntimeLateStatic($context)) {
            $scopeClass = ClassConstFetchHelper::resolveJitScopeClassNameForBlock($objectType, $block) ?? '';
            $nameStr = LateStaticBindingHelper::emitLateStaticResolvedNameString($objectType, $block, $scopeClass);

            return self::boxString($context, $nameStr);
        }

        $called = $context->scope->calledClassName;
        if ('' === $called) {
            $called = $block->func->class->value;
        }

        return self::boxString(
            $context,
            $context->builder->load($context->constantStringFromString($called))
        );
    }

    private static function boxString(Context $context, Value $nativeStr): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $nativeStr
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function emptyStringBox(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $context->builder->load($context->constantStringFromString(''))
        );

        return JitValueBox::pointer($context, $slot);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;

/**
 * LLVM + MCJIT hooks for return-through-finally in JIT (issue #4246).
 *
 * Pending state lives in {@see ReturnPendingJitHelper} PHP via {@see ReturnPendingRuntime} (#9663).
 */
final class JitReturnPending
{
    public static function ensureLinked(Context $context): void
    {
        ReturnPendingRuntime::implement($context);
    }

    /** LLVM bodies for standalone AOT — routes through ReturnPendingJitHelper PHP (#9663). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::registerDeclarations($context);
        ReturnPendingRuntime::implement($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');

        $decls = [
            'phpc_jit_clear_return_pending' => [$void, false, []],
            'phpc_jit_has_return_pending' => [$i32, false, []],
            'phpc_jit_return_pending_is_void' => [$i32, false, []],
            'phpc_jit_set_return_pending' => [$void, false, [$valuePtr, $i32]],
            'phpc_jit_take_return_pending' => [$valuePtr, false, []],
        ];
        foreach ($decls as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    public static function setPending(Context $context, ?Variable $returnVar, bool $isVoid): void
    {
        self::registerDeclarations($context);
        self::ensureLinked($context);
        $builder = $context->builder;
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        if ($isVoid) {
            $builder->call(
                $context->lookupFunction('phpc_jit_set_return_pending'),
                $valuePtr->constNull(),
                $i32->constInt(1, false)
            );

            return;
        }
        if (null === $returnVar) {
            throw new \LogicException('JIT return-through-finally requires a return value');
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $returnVar);
        $builder->call(
            $context->lookupFunction('phpc_jit_set_return_pending'),
            $ptr,
            $i32->constInt(0, false)
        );
    }
}

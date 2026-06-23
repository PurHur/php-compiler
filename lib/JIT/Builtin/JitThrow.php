<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * LLVM + MCJIT hooks for JIT try/catch object throws (issues #57, #195, #1056).
 */
final class JitThrow
{
    private static ?int $clearAddress = null;

    private static ?int $hasAddress = null;

    private static ?int $takeAddress = null;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** LLVM bodies for standalone AOT — routes through ExceptionJitHelper PHP (#9679). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::registerDeclarations($context);
        ExceptionThrowRuntime::implement($context);
    }

    public static function implement(Context $context): void
    {
        ExceptionThrowRuntime::implement($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $objPtr = $context->getTypeFromString('__object__*');

        $decls = [
            'phpc_jit_clear_throw_pending' => [$void, false, []],
            'phpc_jit_has_throw_pending' => [$i32, false, []],
            'phpc_jit_set_throw_pending' => [$void, false, [$objPtr]],
            'phpc_jit_take_throw_pending' => [$objPtr, false, []],
            'phpc_jit_clear_active_catch' => [$void, false, []],
            'phpc_jit_get_active_catch' => [$objPtr, false, []],
            'phpc_jit_set_active_catch' => [$void, false, [$objPtr]],
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

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        self::$clearAddress = $engine->getFunctionAddress('phpc_jit_clear_throw_pending');
        self::$hasAddress = $engine->getFunctionAddress('phpc_jit_has_throw_pending');
        self::$takeAddress = $engine->getFunctionAddress('phpc_jit_take_throw_pending');
    }

    public static function clearPendingAtRunEntry(): void
    {
        if (null === self::$clearAddress || 0 === self::$clearAddress) {
            return;
        }
        $cb = self::callableFromAddress('void(*)()', self::$clearAddress);
        $cb();
    }

    public static function throwPendingIfAny(): void
    {
        if (null === self::$hasAddress || 0 === self::$hasAddress
            || null === self::$takeAddress || 0 === self::$takeAddress
        ) {
            return;
        }
        $has = self::callableFromAddress('int(*)()', self::$hasAddress);
        if (0 === $has()) {
            return;
        }
        throw new \Exception('Uncaught exception in JIT');
    }

    /**
     * @return callable
     */
    private static function callableFromAddress(string $ctype, int $address): callable
    {
        $code = \FFI::new('uintptr_t');
        $code->cdata = $address;
        $cb = \FFI::new($ctype);
        \FFI::memcpy(\FFI::addr($cb), \FFI::addr($code), \FFI::sizeof($cb));

        return $cb;
    }
}

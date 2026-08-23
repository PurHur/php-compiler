<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ReflectionClassGetConstantsRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ReflectionClass::getConstants() — JIT/AOT (#34109, #6950, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → NULL.
 * Optional ?int $filter must be a compile-time int when present
 * (ReflectionClassConstant::IS_*).
 *
 * Dispatches by ReflectionClass name (peer {@see ReflectionClassGetFileName}) so
 * internal classes like stdClass return [] instead of aborting via
 * classIdFromRuntimeName.
 *
 * php-src: zim_ReflectionClass_getConstants
 */
final class ReflectionClassGetConstants implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'ReflectionClass::getConstants',
            0,
            1
        )) {
            return JitValueBox::alloc($context);
        }

        $userArgCount = \count($args) - 1;
        $filter = 0;
        if (1 === $userArgCount) {
            $resolved = self::compileTimeFilter($context, $args[1]);
            if (null === $resolved) {
                throw new \LogicException(
                    'ReflectionClass::getConstants() filter must be a compile-time int in this compiler build'
                );
            }
            $filter = $resolved;
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        return ReflectionClassGetConstantsRuntime::emit($context, $cstr, $len, $filter);
    }

    private static function compileTimeFilter(Context $context, Variable $arg): ?int
    {
        if (null !== $arg->compileTimeLong) {
            return (int) $arg->compileTimeLong;
        }
        if (Variable::TYPE_NATIVE_LONG !== $arg->type || Variable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (null === $arg->value) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }
}

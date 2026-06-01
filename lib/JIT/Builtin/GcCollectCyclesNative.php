<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM declarations for phpc_gc.c (#3160). */
final class GcCollectCyclesNative
{
    public static function registerDeclarations(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');

        self::declare($context, 'phpc_gc_register', $void, [$i8p, $i32]);
        self::declare($context, 'phpc_gc_unregister', $void, [$i8p]);
        self::declare($context, '__compiler_gc_collect_cycles', $i64, []);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function declare(
        Context $context,
        string $name,
        \PHPLLVM\Type $returnType,
        array $paramTypes
    ): void {
        if (null !== $context->module->getNamedFunction($name)) {
            return;
        }
        $fnType = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = $context->module->addFunction($name, $fnType);
        $context->registerFunction($name, $fn);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** LLVM declarations for phpc_lazy.c (#4940). */
final class LazyObjectNative
{
    public static function registerDeclarations(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');

        self::declare($context, 'phpc_lazy_register', $void, [$i8p, $i32, $i32]);
        self::declare($context, 'phpc_lazy_is_pending', $i32, [$i8p]);
        self::declare($context, 'phpc_lazy_is_ghost', $i32, [$i8p]);
        self::declare($context, 'phpc_lazy_init_index', $i32, [$i8p]);
        self::declare($context, 'phpc_lazy_mark_done', $void, [$i8p]);
        self::declare($context, 'phpc_lazy_unregister', $void, [$i8p]);
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

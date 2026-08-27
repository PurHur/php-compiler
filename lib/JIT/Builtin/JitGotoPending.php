<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

final class JitGotoPending
{
    public static function ensureLinked(Context $context): void
    {
        self::registerDeclarations($context);
        GotoPendingRuntime::implement($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        foreach ([
            'phpc_jit_clear_goto_pending' => [$void, false, []],
            'phpc_jit_has_goto_pending' => [$i32, false, []],
            'phpc_jit_set_goto_pending' => [$void, false, []],
        ] as $name => [$ret, $vararg, $params]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    public static function setPending(Context $context): void
    {
        self::ensureLinked($context);
        $context->builder->call($context->lookupFunction('phpc_jit_set_goto_pending'));
    }
}

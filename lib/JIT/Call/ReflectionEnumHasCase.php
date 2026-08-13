<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionEnumJitHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** ReflectionEnum::hasCase($name) — JIT (#9892, #30865). */
final class ReflectionEnumHasCase implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (exactly 1); $args[0] is $this
        $userArgCount = \count($args) - 1;
        if (1 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'ReflectionEnum::hasCase() expects exactly 1 argument, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'refl_enum_hascase_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);

        return ReflectionEnumJitHelper::emitHasCase($context, $obj, $args[1]);
    }
}

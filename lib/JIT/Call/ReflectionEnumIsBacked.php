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

/** ReflectionEnum::isBacked() — JIT (#9892, #30929). */
final class ReflectionEnumIsBacked implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: ext/reflection/php_reflection.c — ZEND_PARSE_PARAMETERS (0 args); $args[0] is $this
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'ReflectionEnum::isBacked() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append($context, 'refl_enum_isbacked_argc_unreach');
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);

        return ReflectionEnumJitHelper::emitIsBacked($context, $obj);
    }
}

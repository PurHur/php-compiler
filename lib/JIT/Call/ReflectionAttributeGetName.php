<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class ReflectionAttributeGetName implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionAttribute_getName — 0 user args (#30896)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf(
                    'ReflectionAttribute::getName() expects exactly 0 arguments, %d given',
                    $userArgCount
                )
            );
            BasicBlockHelper::ensureOpenInsertBlock($context, 'refl_attr_getname_argc_cont');

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $nameVar = $context->type->object->propertyFetch($obj, 'ReflectionAttribute', 'name');
        $valuePtr = $context->helper->loadValue($nameVar);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $context->builder->pointerCast($valuePtr, $context->getTypeFromString('__value__*'))
        );
    }
}

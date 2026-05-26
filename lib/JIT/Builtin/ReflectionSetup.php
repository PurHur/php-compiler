<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** Shared LLVM helpers for native reflection object setup (#1936). */
final class ReflectionSetup
{
    public static function loadObjectFromArg(Context $context, Variable $arg): Value
    {
        $raw = $context->helper->loadValue($arg);
        $rawTy = $context->getStringFromType($raw->typeOf());
        if ('__object__*' === $rawTy) {
            return $raw;
        }
        if ('__value__' === $rawTy) {
            $slot = JitValueBox::alloc($context);
            $context->builder->store($raw, $slot);
            $raw = JitValueBox::pointer($context, $slot);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $raw
        );
    }

    public static function emitSetClassFromStringVar(Context $context, Value $obj, Variable $nameVar): void
    {
        $nameVar = JitNativeString::coerce($context, $nameVar);
        $strPtr = $context->helper->loadValue($nameVar);
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($strPtr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $len = $context->builder->load($lenPtr);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $context->builder->call(
            $context->lookupFunction('phpc_reflect_set_class'),
            $context->builder->pointerCast($obj, $context->getTypeFromString('__object__*')),
            $context->builder->pointerCast($data, $i8p),
            $context->builder->zExt($len, $context->getTypeFromString('size_t'))
        );
    }
}

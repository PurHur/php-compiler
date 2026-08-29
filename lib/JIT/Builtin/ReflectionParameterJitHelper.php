<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** Allocate ReflectionParameter objects for thin AOT internal functions (#28780). */
final class ReflectionParameterJitHelper
{
    public static function emitInternalParamObjectFromLookup(
        Context $context,
        Value $funcCstr,
        Value $indexI64,
    ): Value {
        LibcExtern::ensureStrlenDecl($context);
        $namePtr = $context->builder->call(
            $context->lookupFunction('__compiler_refl_func_param_name_at'),
            $funcCstr,
            $indexI64
        );
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $obj = $context->type->object->allocate(
            $context->type->object->lookup('ReflectionParameter')
        );
        ReflectionSetup::markConstructed($context, $obj);
        $emptyCstr = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $zeroLen = $sizeT->constInt(0, false);
        foreach (
            [
                ReflectionSupport::PROP_PARAM_CLASS,
                ReflectionSupport::PROP_METHOD_NAME,
            ] as $prop
        ) {
            ReflectionSetup::emitSetStringPropertyFromCstr(
                $context,
                $obj,
                'ReflectionParameter',
                $prop,
                $emptyCstr,
                $zeroLen
            );
        }
        $nameLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->pointerCast($namePtr, $i8p)
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_NAME,
            $context->builder->pointerCast($namePtr, $i8p),
            $context->builder->zExt($nameLen, $sizeT)
        );
        $funcLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $funcCstr
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_FUNC_NAME,
            $funcCstr,
            $context->builder->zExt($funcLen, $sizeT)
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_INDEX,
            $indexI64
        );
        ReflectionSetup::emitSetLongPropertyFromValue(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_POSITION,
            $indexI64
        );

        return $obj;
    }
}

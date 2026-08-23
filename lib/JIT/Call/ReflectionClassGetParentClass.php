<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassGetParentClassRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getParentClass() — JIT/AOT (#34069, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod → SIGSEGV on result use.
 * php-src: zim_ReflectionClass_getParentClass — ReflectionClass|false.
 */
final class ReflectionClassGetParentClass implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_getParentClass — 0 args; $args[0] is $this (#30888)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getParentClass',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getparent_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $parentStr = ReflectionClassGetParentClassRuntime::invoke($context, $cstr, $len);

        $resultSlot = JitValueBox::alloc($context);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $parentStr,
            $strPtrTy->constNull()
        );
        $falseBB = BasicBlockHelper::append($context, 'refl_gpc_false');
        $trueBB = BasicBlockHelper::append($context, 'refl_gpc_obj');
        $contBB = BasicBlockHelper::append($context, 'refl_gpc_cont');
        $context->builder->branchIf($isNull, $falseBB, $trueBB);

        $context->builder->positionAtEnd($falseBB);
        JitValueBox::writeBool(
            $context,
            $resultSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($contBB);

        $context->builder->positionAtEnd($trueBB);
        $i8p = $context->getTypeFromString('int8*');
        $raw = $context->builder->pointerCast($parentStr, $i8p);
        $lenPtr = $context->builder->pointerCast(
            $context->builder->gep($raw, $context->constantFromInteger(8, 'size_t')),
            $context->getTypeFromString('int64*')
        );
        $parentLen = $context->builder->load($lenPtr);
        $data = $context->builder->gep($raw, $context->constantFromInteger(16, 'size_t'));
        $parentCstr = $context->builder->pointerCast($data, $i8p);
        $parentLenSz = $context->builder->zExt(
            $parentLen,
            $context->getTypeFromString('size_t')
        );

        $rcClassId = $context->type->object->lookup('ReflectionClass');
        $rcObj = $context->type->object->allocate($rcClassId);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rcObj,
            'ReflectionClass',
            ReflectionSupport::PROP_CLASS_NAME,
            $parentCstr,
            $parentLenSz
        );
        ReflectionSetup::markConstructed($context, $rcObj);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $resultSlot),
            $rcObj
        );
        $context->builder->branch($contBB);

        $context->builder->positionAtEnd($contBB);

        return JitValueBox::pointer($context, $resultSlot);
    }
}

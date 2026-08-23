<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionClassGetConstructorRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * ReflectionClass::getConstructor() — JIT/AOT (#34073, ext/reflection/php_reflection.c).
 *
 * Thin AOT previously had no proxy; ExternalMethod returned an unseeded object
 * for classes with a constructor → {@see getName()} SIGSEGV. Seed a
 * ReflectionMethod from the declaring-class name table (peer #34020).
 */
final class ReflectionClassGetConstructor implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // php-src: zim_ReflectionClass_getConstructor — 0 args; $args[0] is $this (#31033)
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage(
                    'ReflectionClass::getConstructor',
                    0,
                    $userArgCount
                )
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'refl_class_getconstructor_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$cstr, $len] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);
        $declaringStr = ReflectionClassGetConstructorRuntime::invoke($context, $cstr, $len);

        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $declaringStr,
            $strPtrTy->constNull()
        );
        $nullBlock = BasicBlockHelper::append($context, 'refl_class_getctor_null');
        $hitBlock = BasicBlockHelper::append($context, 'refl_class_getctor_hit');
        $done = BasicBlockHelper::append($context, 'refl_class_getctor_done');
        $context->builder->branchIf($isNull, $nullBlock, $hitBlock);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hitBlock);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];
        $classCstr = $context->builder->pointerCast(
            $context->builder->structGep($declaringStr, $strMap['value']),
            $i8p
        );
        $classLen = $context->builder->load(
            $context->builder->structGep($declaringStr, $strMap['length'])
        );
        $classLen = $context->builder->zExt($classLen, $sizeT);

        $ctorName = $context->builder->load(
            $context->constantStringFromString('__construct')
        );
        $ctorCstr = $context->builder->pointerCast(
            $context->builder->structGep($ctorName, $strMap['value']),
            $i8p
        );
        $ctorLen = $context->builder->load(
            $context->builder->structGep($ctorName, $strMap['length'])
        );
        $ctorLen = $context->builder->zExt($ctorLen, $sizeT);

        $rmClassId = $context->type->object->lookup('ReflectionMethod');
        $rmObj = $context->type->object->allocate($rmClassId);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rmObj,
            'ReflectionMethod',
            'class',
            $classCstr,
            $classLen
        );
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $rmObj,
            'ReflectionMethod',
            'name',
            $ctorCstr,
            $ctorLen
        );
        ReflectionSetup::markConstructed($context, $rmObj);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $rmObj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $destPtr;
    }
}

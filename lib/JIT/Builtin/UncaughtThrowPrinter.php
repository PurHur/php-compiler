<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_;

/**
 * Print Zend-shaped uncaught user throw fatals for JIT/AOT (#23641).
 *
 * php-src: Zend/zend_exceptions.c — zend_exception_error / uncaught Throwable
 */
final class UncaughtThrowPrinter
{
    /**
     * fprintf(stderr, Zend uncaught shape) then exit(255). Replaces silent abort().
     *
     * Straight-line IR: helpers that clearInsertionPosition must not leave us in a
     * terminated block before exit(255) (#23641).
     */
    public static function emitPrintAndExit(Context $context, Value $exceptionObj): void
    {
        self::ensureLibcDecls($context);
        $builder = $context->builder;
        $startBb = BasicBlockHelper::tryGetInsertBlock($context);
        if (null === $startBb || !$startBb->getParent() instanceof Function_) {
            throw new \LogicException('UncaughtThrowPrinter requires parent function');
        }

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $objPtrTy = $context->getTypeFromString('__object__*');

        $emptyCstr = self::cstrPtr($context, '');
        $unknownCstr = self::cstrPtr($context, 'Unknown');
        $excCstr = self::cstrPtr($context, 'Exception');

        $isNullObj = $builder->icmp(Builder::INT_EQ, $exceptionObj, $objPtrTy->constNull());
        $objMap = $context->structFieldMap['__object__'];
        $classId = $builder->load($builder->structGep($exceptionObj, $objMap['class_id']));
        // Prefer compile-unit class map (no NestedJIT GetClass helper — avoids insert wipe #23641).
        $classCstr = self::classNameCstrFromId($context, $classId, $excCstr);
        $classCstr = $builder->select($isNullObj, $excCstr, $classCstr);

        $msgStr = self::tryReadStringProp($context, $exceptionObj, ExceptionSupport::PROP_MESSAGE);
        $msgCstr = self::stringDataOrFallback($context, $msgStr, $emptyCstr);
        $msgCstr = $builder->select($isNullObj, $emptyCstr, $msgCstr);

        $fileStr = self::tryReadStringProp($context, $exceptionObj, ExceptionSupport::PROP_FILE);
        $fileCstr = self::stringDataOrFallback($context, $fileStr, $unknownCstr);
        $fileCstr = $builder->select($isNullObj, $unknownCstr, $fileCstr);

        $lineLong = self::tryReadLongProp($context, $exceptionObj, ExceptionSupport::PROP_LINE);
        $lineI32 = $builder->trunc($lineLong, $i32);
        $zeroI32 = $i32->constInt(0, false);
        $lineOut = $builder->select($isNullObj, $zeroI32, $lineI32);

        $lineBuf = $builder->alloca($i8->arrayType(1024), 1, 'uncaught_line');
        $linePtr = $builder->pointerCast($lineBuf, $i8p);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $builder->call(
            $context->lookupFunction('snprintf'),
            $linePtr,
            $context->constantFromInteger(1024, 'size_t'),
            self::cstrPtr($context, "PHP Fatal error:  Uncaught %s: %s in %s:%d\n"),
            $classCstr,
            $msgCstr,
            $fileCstr,
            $lineOut
        );
        $stderr = StringTriggerErrorJit::stderrFilePtr($context);
        $builder->call(
            $context->lookupFunction('fprintf'),
            $stderr,
            self::cstrPtr($context, '%s'),
            $linePtr
        );
        $builder->call(
            $context->lookupFunction('fprintf'),
            $stderr,
            self::cstrPtr($context, "Stack trace:\n#0 {main}\n  thrown in %s on line %d\n"),
            $fileCstr,
            $lineOut
        );
        $builder->call($context->lookupFunction('exit'), $i32->constInt(255, false));
        $context->llvm->lib->LLVMBuildUnreachable($builder->builder);
    }

    /** Inline class_id → name cstr from the current Object_ map (no NestedJIT). */
    private static function classNameCstrFromId(Context $context, Value $classId, Value $fallback): Value
    {
        $builder = $context->builder;
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $names = $context->type->object->allClassNamesById();
        if ([] === $names) {
            return $fallback;
        }
        $func = BasicBlockHelper::parentFunction($context);
        $join = $func->appendBasicBlock('uncaught_cn_join');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $builder->store($fallback, $resultSlot);
        $check = $builder->getInsertBlock();
        $lastId = array_key_last($names);
        foreach ($names as $id => $name) {
            $builder->positionAtEnd($check);
            $matchBb = $func->appendBasicBlock('uncaught_cn_'.$id);
            $nextBb = ((int) $id === (int) $lastId)
                ? $join
                : $func->appendBasicBlock('uncaught_cn_try_'.$id);
            $eq = $builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt((int) $id, false)
            );
            $builder->branchIf($eq, $matchBb, $nextBb);
            $builder->positionAtEnd($matchBb);
            $builder->store(self::cstrPtr($context, $name), $resultSlot);
            $builder->branch($join);
            $check = $nextBb;
        }
        $builder->positionAtEnd($join);

        return $builder->load($resultSlot);
    }

    private static function stringDataOrFallback(Context $context, Value $strPtr, Value $fallbackCstr): Value
    {
        $builder = $context->builder;
        $i8p = $context->getTypeFromString('int8*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $func = BasicBlockHelper::parentFunction($context);
        $nullBb = $func->appendBasicBlock('uncaught_str_null');
        $dataBb = $func->appendBasicBlock('uncaught_str_data');
        $joinBb = $func->appendBasicBlock('uncaught_str_join');
        $isNull = $builder->icmp(Builder::INT_EQ, $strPtr, $strPtrTy->constNull());
        $builder->branchIf($isNull, $nullBb, $dataBb);

        $builder->positionAtEnd($nullBb);
        $builder->branch($joinBb);

        $builder->positionAtEnd($dataBb);
        $strMap = $context->structFieldMap['__string__'];
        $data = $builder->pointerCast(
            $builder->structGep($strPtr, $strMap['value']),
            $i8p
        );
        $builder->branch($joinBb);

        $builder->positionAtEnd($joinBb);
        $phi = $builder->phi($i8p);
        $phi->addIncoming($fallbackCstr, $nullBb);
        $phi->addIncoming($data, $dataBb);

        return $phi;
    }

    private static function tryReadStringProp(Context $context, Value $obj, string $prop): Value
    {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        foreach (['Exception', 'Error'] as $class) {
            try {
                $classId = $context->type->object->lookup($class);
            } catch (\Throwable) {
                if (null !== $insert) {
                    BasicBlockHelper::restoreInsertBlock($context, $insert);
                }
                continue;
            }
            if (!$context->type->object->hasProperty($classId, $prop)) {
                continue;
            }
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            $fetched = $context->type->object->propertyFetch($obj, $class, $prop);
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            if (Variable::TYPE_STRING === $fetched->type) {
                return $context->helper->loadValue($fetched);
            }
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $fetched);

            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $valuePtr
            );
        }
        if (null !== $insert) {
            BasicBlockHelper::restoreInsertBlock($context, $insert);
        }

        return $strPtrTy->constNull();
    }

    private static function tryReadLongProp(Context $context, Value $obj, string $prop): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        foreach (['Exception', 'Error'] as $class) {
            try {
                $classId = $context->type->object->lookup($class);
            } catch (\Throwable) {
                if (null !== $insert) {
                    BasicBlockHelper::restoreInsertBlock($context, $insert);
                }
                continue;
            }
            if (!$context->type->object->hasProperty($classId, $prop)) {
                continue;
            }
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            $fetched = $context->type->object->propertyFetch($obj, $class, $prop);
            if (null !== $insert) {
                BasicBlockHelper::restoreInsertBlock($context, $insert);
            }
            if (Variable::TYPE_NATIVE_LONG === $fetched->type) {
                return $context->helper->loadValue($fetched);
            }
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $fetched);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );
        }
        if (null !== $insert) {
            BasicBlockHelper::restoreInsertBlock($context, $insert);
        }

        return $i64->constInt(0, false);
    }

    private static function cstrPtr(Context $context, string $literal): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($literal),
            $context->getTypeFromString('int8*')
        );
    }

    private static function ensureLibcDecls(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $void = $context->context->voidType();

        if (null === $context->module->getNamedGlobal('stderr')) {
            $context->module->addGlobal($i8p, 'stderr');
        }

        TypeErrorRaise::ensureDeclInScope(
            $context,
            'fprintf',
            $context->context->functionType($i32, true, $i8p, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $i8p, $sizeT, $i8p)
        );
        TypeErrorRaise::ensureDeclInScope(
            $context,
            'exit',
            $context->context->functionType($void, false, $i32)
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Zend-shaped uncaught fatal for user `throw` in AOT/JIT (#23641).
 *
 * Built as a standalone LLVM function so try/catch dispatch can call it without
 * emitting IR into a builder that NestedJIT may have detached.
 * php-src: Zend/zend_exceptions.c — zend_exception_error
 */
final class UncaughtThrowableRaise
{
    private const ABI = 'phpc_jit_uncaught_throwable_fatal';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function emitCall(Context $context, Value $exceptionObj): void
    {
        self::ensureLinked($context);
        $context->builder->call($context->lookupFunction(self::ABI), $exceptionObj);
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && 0 < $probe->countBasicBlocks()) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->context->voidType();
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');

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
            'exit',
            $context->context->functionType($void, false, $i32)
        );

        $ft = $context->context->functionType($void, false, $objPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::ABI, $ft);
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $exceptionObj = $fn->getParam(0);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($exceptionObj, $objMap['class_id'])
        );

        // Compile-unit class table (not GetClassRuntime — that helper can be frozen before
        // Throwable classes are registered, yielding an empty %s in the fatal line).
        $classNameStr = self::emitClassNameSelect($context, $classId);

        // Exception::$message @ slot matching seedThrowableExternalClass / ExceptionGetMessage.
        $object = $context->type->object;
        $object->lookup('Exception'); // ensure Exception props exist
        $msgSlot = $object->propertySlotFor($exceptionObj, 'Exception', ExceptionSupport::PROP_MESSAGE);
        $msgLoaded = $context->builder->load($msgSlot);
        $messageStr = $context->builder->pointerCast($msgLoaded, $strPtr);

        $strMap = $context->structFieldMap['__string__'];
        $nullStr = $strPtr->constNull();
        $hasMsg = $context->builder->icmp(Builder::INT_NE, $messageStr, $nullStr);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $messageStr = $context->builder->select($hasMsg, $messageStr, $emptyStr);

        $classCstr = $context->builder->structGep($classNameStr, $strMap['value']);
        $msgCstr = $context->builder->structGep($messageStr, $strMap['value']);
        $stderrPtr = StringTriggerErrorJit::stderrFilePtr($context);

        $context->builder->call(
            $context->lookupFunction('fprintf'),
            $stderrPtr,
            $context->builder->pointerCast(
                $context->constantFromString("PHP Fatal error:  Uncaught %s: %s\nStack trace:\n#0 {main}\n"),
                $i8p
            ),
            $context->builder->pointerCast($classCstr, $i8p),
            $context->builder->pointerCast($msgCstr, $i8p)
        );
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(255, false)
        );
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();

        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
    }

    private static function emitClassNameSelect(Context $context, Value $classId): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $fallback = $context->builder->load($context->constantStringFromString('Error'));
        $selected = $fallback;
        foreach ($context->type->object->allClassNamesById() as $id => $storedName) {
            $display = ThrowableManifest::nameForLc(strtolower(ltrim((string) $storedName, '\\')))
                ?? (string) $storedName;
            if ('' === $display) {
                continue;
            }
            $nameStr = $context->builder->load($context->constantStringFromString($display));
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt((int) $id, false)
            );
            $selected = $context->builder->select($match, $nameStr, $selected);
        }

        return $selected;
    }
}

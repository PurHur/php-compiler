<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_object_id() / spl_object_id() — object handle as ptrToInt (#3537, #3172, #5291, #8941). */
final class JitGetObjectId
{
    public static function invoke(Context $context, JITVariable $arg, string $function = 'get_object_id'): Value
    {
        if (null !== $arg->compileTimeEnumCase) {
            return self::enumCaseSingletonHandle($context, $arg);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return self::objectHandle($context, $context->helper->loadValue($arg));
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::boxed($context, $arg, $function);
        }

        self::emitTypeErrorAndAbort(
            $context,
            self::typeErrorMessage($function, \PHPCompiler\JIT\JitOperandTypeLabel::givenLabel($context, $arg))
        );

        return $context->constantFromInteger(0, 'int64');
    }

    private static function enumCaseSingletonHandle(Context $context, JITVariable $arg): Value
    {
        $objectType = $context->type->object;
        if (!$objectType instanceof JitObjectType) {
            throw new \LogicException('enum case object id requires Object_ JIT helper');
        }
        $classId = (int) $arg->compileTimeEnumCase['classId'];
        $caseKey = (string) $arg->compileTimeEnumCase['caseKey'];
        $globalName = $objectType->ensureEnumCaseSingletonGlobal($classId, $caseKey);
        $global = $context->module->getNamedGlobal($globalName);
        if (null === $global) {
            throw new \LogicException("Missing enum case singleton global: {$globalName}");
        }
        $obj = $context->builder->load($global);

        return self::objectHandle($context, $obj);
    }

    public static function boxed(Context $context, JITVariable $arg, string $function = 'get_object_id'): Value
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED — writers store JIT TYPE_OBJECT (5|0x80) or VM kind 5 (#28661 / #21921).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'get_object_id_ok');
        $checkBool = BasicBlockHelper::append($context, 'get_object_id_check_bool');
        $boolBlock = BasicBlockHelper::append($context, 'get_object_id_bool');
        $mixedErr = BasicBlockHelper::append($context, 'get_object_id_mixed');
        $context->builder->branchIf($isObject, $okBlock, $checkBool);

        $context->builder->positionAtEnd($checkBool);
        // writeBool stores JIT TYPE_NATIVE_BOOL (not VM TYPE_BOOLEAN) (#30100).
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL & 0x7f, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $mixedErr);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $loaded);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $trueErr = BasicBlockHelper::append($context, 'get_object_id_true');
        $falseErr = BasicBlockHelper::append($context, 'get_object_id_false');
        $context->builder->branchIf($isTrue, $trueErr, $falseErr);
        $context->builder->positionAtEnd($trueErr);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'true'));
        $context->builder->positionAtEnd($falseErr);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'false'));

        $context->builder->positionAtEnd($mixedErr);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'mixed'));

        $context->builder->positionAtEnd($okBlock);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $loaded
        );

        return self::objectHandle($context, $obj);
    }

    private static function objectHandle(Context $context, Value $obj): Value
    {
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->ptrToInt(
            $context->builder->pointerCast($obj, $voidp),
            $i64
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function typeErrorMessage(string $function, string $given): string
    {
        return \sprintf('%s(): Argument #1 ($object) must be of type object, %s given', $function, $given);
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Lower string builtin operands with Z_PARAM_STR parity (#5780, ext/standard/string.c).
 *
 * Runtime strictness hook (#7361): future php-compiler-strict skips need static proof
 * before omitting enum-case / object rejection blocks here; default stays php-src-strict.
 */
final class JitStringBuiltinArg
{
    public static function lower(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        $arrayExpected = $arrayExpectedType ?? $expectedType;
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

                return self::unreachableStringPtr($context);
            }

            return $context->builder->load($context->constantStringFromString(''));
        }
        if (Variable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpected);

            return self::unreachableStringPtr($context);
        }
        if (0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpected);

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitRejectTypeError($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            $native = JitNativeString::coerce($context, $arg);

            return $context->helper->loadValue($native);
        }

        return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
    }

    /**
     * Emit a Z_PARAM_STR TypeError for a runtime object operand (#10166, ext/standard/string.c).
     */
    public static function emitObjectTypeErrorReject(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex = 0,
        string $paramName = 'string',
        string $expectedType = 'string'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitRuntimeObjectReject($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return;
        }
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);
    }

    /**
     * Lower string builtin operands with strict Z_PARAM_STR parity (#5018, ext/standard/string.c).
     *
     * Rejects int/float/bool operands that {@see lower()} would coerce via JitStringArg.
     */
    public static function lowerRequiredString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        if (Variable::TYPE_HASHTABLE === $arg->type || Variable::TYPE_OBJECT === $arg->type) {
            return self::lower($context, $arg, $function, $argIndex, $paramName);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerRequiredBoxed($context, $arg, $function, $argIndex, $paramName);
        }
        if (Variable::TYPE_STRING !== $arg->type) {
            $errBlock = BasicBlockHelper::append($context, 'str_req_scalar_err');
            $context->builder->branch($errBlock);
            $context->builder->positionAtEnd($errBlock);
            self::emitTypeErrorAndAbort(
                $context,
                $function,
                $argIndex,
                $paramName,
                JitOperandTypeLabel::givenLabel($context, $arg)
            );

            return self::unreachableStringPtr($context);
        }

        return $context->helper->loadValue($arg);
    }

    private static function lowerRequiredBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $stringTy = $i8->constInt(VmVariable::TYPE_STRING, false);

        $arrayBlock = BasicBlockHelper::append($context, 'str_req_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_req_reject');
        $stringBlock = BasicBlockHelper::append($context, 'str_req_string');
        $scalarBlock = BasicBlockHelper::append($context, 'str_req_scalar');
        $okBlock = BasicBlockHelper::append($context, 'str_req_ok');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array');

        $context->builder->positionAtEnd($okBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $scalarBlock);

        $enumRejectBlock = BasicBlockHelper::append($context, 'str_req_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'str_req_object_reject');
        $context->builder->positionAtEnd($rejectBlock);
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName
        );
        $context->builder->positionAtEnd($objectRejectBlock);
        self::emitRuntimeBoxedObjectReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName
        );

        $context->builder->positionAtEnd($scalarBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeKind, $stringTy);
        $context->builder->branchIf($isString, $stringBlock, $rejectBlock);

        $context->builder->positionAtEnd($stringBlock);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function lowerBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        string $arrayExpectedType = 'string'
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $arrayTy = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objectTy = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $enumCaseTy = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);

        $okBlock = BasicBlockHelper::append($context, 'str_builtin_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'str_builtin_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_builtin_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'str_builtin_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpectedType);

        $context->builder->positionAtEnd($okBlock);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $enumRejectBlock = BasicBlockHelper::append($context, 'str_builtin_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'str_builtin_object_reject');
        $context->builder->positionAtEnd($rejectBlock);
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );
        $context->builder->positionAtEnd($objectRejectBlock);
        self::emitRuntimeBoxedObjectReject(
            $context,
            $valuePtr,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );

        $context->builder->positionAtEnd($coerceBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $strictOkBlock = BasicBlockHelper::append($context, 'str_builtin_strict_ok');
            $strictErrBlock = BasicBlockHelper::append($context, 'str_builtin_strict_err');
            $context->builder->branchIf($isString, $strictOkBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed', $expectedType);
            $context->builder->positionAtEnd($strictOkBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    private static function emitRejectTypeError(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
        $compileTimeLabel = JitOperandTypeLabel::compileTimeEnumClassName($context, $arg);
        if (null !== $compileTimeLabel) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, $compileTimeLabel, $expectedType);

            return;
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            self::emitRuntimeObjectReject($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return;
        }
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);
    }

    private static function emitRuntimeObjectReject(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $objPtr = Variable::KIND_VALUE === $arg->kind
            ? $arg->value
            : $context->builder->load($arg->value);
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        self::emitRuntimeEnumClassIdReject($context, $classId, $function, $argIndex, $paramName, $expectedType);
    }

    private static function emitRuntimeBoxedEnumCaseReject(
        Context $context,
        Value $valuePtr,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $classId = $context->builder->load(
            $context->builder->structGep($valuePtr, $enumMap['class_id'])
        );
        self::emitRuntimeEnumClassIdReject($context, $classId, $function, $argIndex, $paramName, $expectedType);
    }

    /**
     * Boxed enum-case vs object reject for strlen() Z_PARAM_STR parity (#10166).
     */
    public static function emitRuntimeBoxedRejectForStrlen(
        Context $context,
        Value $valuePtr,
        Value $isEnumCase
    ): void {
        $enumRejectBlock = BasicBlockHelper::append($context, 'strlen_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'strlen_object_reject');
        $context->builder->branchIf($isEnumCase, $enumRejectBlock, $objectRejectBlock);
        $context->builder->positionAtEnd($enumRejectBlock);
        self::emitRuntimeBoxedEnumCaseReject($context, $valuePtr, 'strlen', 0, 'string', 'string');
        $context->builder->positionAtEnd($objectRejectBlock);
        self::emitRuntimeBoxedObjectReject($context, $valuePtr, 'strlen', 0, 'string', 'string');
    }

    private static function emitRuntimeBoxedObjectReject(
        Context $context,
        Value $valuePtr,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objMap = $context->structFieldMap['__object__'] ?? null;
        if (null === $objMap || !isset($objMap['class_id'])) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        self::emitRuntimeEnumClassIdReject($context, $classId, $function, $argIndex, $paramName, $expectedType);
    }

    private static function emitRuntimeEnumClassIdReject(
        Context $context,
        Value $classId,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType
    ): void {
        $jitObject = $context->type->object;
        if (!$jitObject instanceof JitObjectType) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $enumNames = $jitObject->allDeclaredEnumLowerNames();
        if ([] === $enumNames) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $okBlock = BasicBlockHelper::append($context, 'str_enum_class_reject_ok');
        $ids = [];
        foreach ($enumNames as $lc) {
            $enumId = $jitObject->lookup($lc);
            $ids[] = [$enumId, $jitObject->classNameForId($enumId)];
        }
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => [$enumId, $enumName]) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($enumId, false)
            );
            $rejectBlock = $fn->appendBasicBlock('str_enum_class_reject_'.$enumId);
            $nextBlock = $idx === $lastIdx
                ? $okBlock
                : $fn->appendBasicBlock('str_enum_class_try_'.($idx + 1));
            $context->builder->branchIf($match, $rejectBlock, $nextBlock);
            $context->builder->positionAtEnd($rejectBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, $enumName, $expectedType);
            $checkBlock = $nextBlock;
        }
        if ($checkBlock !== $okBlock) {
            $context->builder->positionAtEnd($checkBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);
            $context->builder->branch($okBlock);
        }
        $context->builder->positionAtEnd($okBlock);
    }

    private static function typeErrorMessage(
        string $function,
        int $argIndex,
        string $paramName,
        string $given,
        string $expectedType = 'string'
    ): string {
        return sprintf(
            '%s(): Argument #%d ($%s) must be of type %s, %s given',
            $function,
            $argIndex + 1,
            $paramName,
            $expectedType,
            $given
        );
    }

    private static function emitTypeErrorAndAbort(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $given,
        string $expectedType = 'string'
    ): void {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $message = self::typeErrorMessage($function, $argIndex, $paramName, $given, $expectedType);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);

            return;
        }
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }

    /** Compile-time string operand for builtins that only lower literals (timezone_open, date_create, …). */
    public static function compileTimeLiteral(Variable $arg): ?string
    {
        return JitStringArg::compileTimeLiteral($arg);
    }

    /**
     * Reject empty string operands after lowering (php-src dir.c / ini.c empty-path guards; #11031).
     *
     * @throws \ValueError when the compile-time operand is empty
     */
    public static function rejectEmpty(Context $context, Variable $arg, Value $loweredStr, string $errorMessage): void
    {
        if (null !== ($arg->compileTimeString ?? null)) {
            if ('' === $arg->compileTimeString) {
                throw new \ValueError($errorMessage);
            }

            return;
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($loweredStr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $failBlock = BasicBlockHelper::append($context, 'str_empty_fail');
        $okBlock = BasicBlockHelper::append($context, 'str_empty_ok');
        $context->builder->branchIf($empty, $failBlock, $okBlock);
        $context->builder->positionAtEnd($failBlock);
        TypeErrorRaise::emitValueError($context, $errorMessage);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}

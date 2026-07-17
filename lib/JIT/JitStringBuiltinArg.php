<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmNullStringParamDeprecation;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Builtin\Type\Object_ as JitObjectType;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\VM\ErrorReporter;
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
    /** Z_PARAM_STR null coerces outside caller strict_types (#19161). */
    public static function requiresForwardProfileStrictStringNull(): bool
    {
        return VmString::requiresForwardProfileStrictStringNull();
    }

    /** Z_PARAM_STR typed operands — null TypeError on 8.4 forward profile (#18840, #18980, #19222). */
    public static function requiresZparamStrStrictNullOnForwardProfile(): bool
    {
        return VmString::requiresZparamStrStrictNullOnForwardProfile();
    }

    /**
     * Z_PARAM_STR with caller strict_types parity (#12276, #12274).
     */
    public static function lowerStrictOrCoercible(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $rejectNullOnForwardProfile = true
    ): Value {
        if ($context->callerStrictTypes) {
            if (Variable::TYPE_VALUE === $arg->type || Variable::TYPE_OBJECT === $arg->type) {
                return self::lowerRequiredString($context, $arg, $function, $argIndex, $paramName);
            }
            if (Variable::TYPE_STRING !== $arg->type) {
                JitNativeString::ensureInsertBlock($context);
                self::emitTypeErrorAndAbort(
                    $context,
                    $function,
                    $argIndex,
                    $paramName,
                    JitOperandTypeLabel::givenLabel($context, $arg)
                );

                return self::unreachableStringPtr($context);
            }

            return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
        }

        return self::lower(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            $arrayExpectedType,
            $rejectNullOnForwardProfile
        );
    }

    /**
     * Z_PARAM_STR — null TypeError on 8.4 forward profile (#18837, #18838, ext/standard/string.c).
     */
    public static function lowerZparamStr(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null
    ): Value {
        return self::lower(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            $arrayExpectedType,
            false,
            true
        );
    }

    public static function lower(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $rejectNullOnForwardProfile = true,
        bool $zparamStrNullGuard = false
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        $arrayExpected = $arrayExpectedType ?? $expectedType;
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            if (
                $context->callerStrictTypes
                || ($zparamStrNullGuard && self::requiresZparamStrStrictNullOnForwardProfile())
                || ($rejectNullOnForwardProfile && self::requiresForwardProfileStrictStringNull())
            ) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

                return self::unreachableStringPtr($context);
            }

            if (!self::requiresForwardProfileStrictStringNull()) {
                self::emitNullStringParamDeprecation($context, $function, $argIndex, $paramName, $expectedType);
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
            if ('string' !== $expectedType) {
                $magic = MagicMethodDispatch::coerceObjectToString($context, $arg);
                if (null !== $magic) {
                    return $context->helper->loadValue($magic);
                }
            }
            self::emitRejectTypeError($context, $arg, $function, $argIndex, $paramName, $expectedType);

            return self::unreachableStringPtr($context);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            // Boxed null (common when null is arg #1 before an array literal) — Z_PARAM_STR
            // TypeError on 8.4 / strict_types (#19894; same mask as JitStrlen).
            if (
                $context->callerStrictTypes
                || ($zparamStrNullGuard && self::requiresZparamStrStrictNullOnForwardProfile())
                || ($rejectNullOnForwardProfile && self::requiresForwardProfileStrictStringNull())
            ) {
                TypeErrorRaise::registerDeclarations($context);
                TypeErrorRaise::ensureLinked($context);
                $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
                $map = $context->structFieldMap['__value__'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($valuePtr, $map['type'])
                );
                $i8 = $context->getTypeFromString('int8');
                $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
                $nullErrBlock = BasicBlockHelper::append($context, 'zparam_str_value_null');
                $okBlock = BasicBlockHelper::append($context, 'zparam_str_value_ok');
                $context->builder->branchIf(
                    $context->builder->icmp(
                        Builder::INT_EQ,
                        $typeKind,
                        $i8->constInt(VmVariable::TYPE_NULL, false)
                    ),
                    $nullErrBlock,
                    $okBlock
                );
                $context->builder->positionAtEnd($nullErrBlock);
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);
                $context->builder->positionAtEnd($okBlock);
            }
            $native = JitNativeString::coerce($context, $arg);

            return $context->helper->loadValue($native);
        }

        return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
    }

    /**
     * Z_PARAM_STR_OR_NULL — null passes through; enum case rejects with ?string TypeError (#17196, ext/standard/info.c).
     */
    public static function lowerNullableString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->getTypeFromString('__string__*')->constNull();
        }

        return self::lower($context, $arg, $function, $argIndex, $paramName, '?string');
    }

    /**
     * Z_PARAM_PATH — null coerces to "" when caller is not strict; TypeError under strict_types (#13419).
     */
    public static function lowerPath(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $softNullPath = false
    ): Value {
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            JitNativeString::ensureInsertBlock($context);
            if (
                !$softNullPath
                && (
                    $context->callerStrictTypes
                    || self::requiresZparamStrStrictNullOnForwardProfile()
                )
            ) {
                self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

                return self::unreachableStringPtr($context);
            }

            if (!$softNullPath && !self::requiresForwardProfileStrictStringNull()) {
                self::emitNullStringParamDeprecation($context, $function, $argIndex, $paramName, $expectedType);
            }

            return $context->builder->load($context->constantStringFromString(''));
        }

        // Boxed null / VALUE: same Z_PARAM_PATH 8.4 null TypeError as Z_PARAM_STR (#19256).
        return self::lower(
            $context,
            $arg,
            $function,
            $argIndex,
            $paramName,
            $expectedType,
            $arrayExpectedType,
            false,
            !$softNullPath
        );
    }

    public static function emitNullStringParamDeprecation(
        Context $context,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
        $message = \sprintf(
            '%s(): Passing null to parameter #%d ($%s) of type %s is deprecated',
            $function,
            $argIndex + 1,
            $paramName,
            $expectedType
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    /**
     * Lower typed string builtin operands (php-src IS_STRING; rejects null, #12640).
     */
    public static function lowerTypedString(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null
    ): Value {
        JitNativeString::ensureInsertBlock($context);
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

            return self::unreachableStringPtr($context);
        }

        return self::lower($context, $arg, $function, $argIndex, $paramName, $expectedType, $arrayExpectedType);
    }

    /**
     * Lower Z_PARAM_STR operands with __toString coercion when caller is not strict (#11398, ext/standard/string.c).
     */
    public static function lowerCoercible(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string',
        ?string $arrayExpectedType = null,
        bool $allowStringableUnderStrict = false
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
            if ($context->callerStrictTypes && !$allowStringableUnderStrict) {
                self::emitRejectTypeError($context, $arg, $function, $argIndex, $paramName, $expectedType);

                return self::unreachableStringPtr($context);
            }
            $native = JitNativeString::coerce($context, $arg);

            return $context->helper->loadValue($native);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerCoercibleBoxed(
                $context,
                $arg,
                $function,
                $argIndex,
                $paramName,
                $expectedType,
                $arrayExpected,
                $allowStringableUnderStrict
            );
        }

        return JitStringArg::lower($context, $arg, "{$function}() argument #" . ($argIndex + 1));
    }

    private static function lowerCoercibleBoxed(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType,
        string $arrayExpectedType,
        bool $allowStringableUnderStrict = false
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

        $okBlock = BasicBlockHelper::append($context, 'str_coercible_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'str_coercible_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_coercible_reject');
        $coerceBlock = BasicBlockHelper::append($context, 'str_coercible_coerce');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeKind, $objectTy);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeKind, $enumCaseTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $arrayExpectedType);

        $context->builder->positionAtEnd($okBlock);
        $isObjOrEnum = $context->builder->or($isObject, $isEnumCase);
        $context->builder->branchIf($isObjOrEnum, $rejectBlock, $coerceBlock);

        $enumRejectBlock = BasicBlockHelper::append($context, 'str_coercible_enum_reject');
        $objectRejectBlock = BasicBlockHelper::append($context, 'str_coercible_object_reject');
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
        if ($context->callerStrictTypes && !$allowStringableUnderStrict) {
            self::emitRuntimeBoxedObjectReject(
                $context,
                $valuePtr,
                $function,
                $argIndex,
                $paramName,
                $expectedType
            );
        } else {
            $objVar = new Variable(
                $context,
                Variable::TYPE_VALUE,
                Variable::KIND_VALUE,
                $valuePtr
            );
            $native = JitNativeString::coerce($context, $objVar);

            return $context->helper->loadValue($native);
        }

        $context->builder->positionAtEnd($coerceBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $strictOkBlock = BasicBlockHelper::append($context, 'str_coercible_strict_ok');
            $strictErrBlock = BasicBlockHelper::append($context, 'str_coercible_strict_err');
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
        string $paramName,
        string $expectedType = 'string'
    ): Value {
        if (Variable::TYPE_HASHTABLE === $arg->type || Variable::TYPE_OBJECT === $arg->type) {
            return self::lower($context, $arg, $function, $argIndex, $paramName, $expectedType);
        }
        if (Variable::TYPE_VALUE === $arg->type) {
            return self::lowerRequiredBoxed($context, $arg, $function, $argIndex, $paramName, $expectedType);
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
                JitOperandTypeLabel::givenLabel($context, $arg),
                $expectedType
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
        string $paramName,
        string $expectedType = 'string'
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
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);

        $nullBlock = BasicBlockHelper::append($context, 'str_req_null');
        $afterNull = BasicBlockHelper::append($context, 'str_req_after_null');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeKind, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'null', $expectedType);

        $context->builder->positionAtEnd($afterNull);
        $arrayBlock = BasicBlockHelper::append($context, 'str_req_array');
        $rejectBlock = BasicBlockHelper::append($context, 'str_req_reject');
        $stringBlock = BasicBlockHelper::append($context, 'str_req_string');
        $scalarBlock = BasicBlockHelper::append($context, 'str_req_scalar');
        $okBlock = BasicBlockHelper::append($context, 'str_req_ok');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeKind, $arrayTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'array', $expectedType);

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

        $context->builder->positionAtEnd($scalarBlock);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeKind, $stringTy);
        $scalarErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_err');
        $context->builder->branchIf($isString, $stringBlock, $scalarErrBlock);

        $context->builder->positionAtEnd($scalarErrBlock);
        self::emitRuntimeBoxedNonStringScalarReject(
            $context,
            $typeKind,
            $function,
            $argIndex,
            $paramName,
            $expectedType
        );

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

    private static function emitRuntimeBoxedNonStringScalarReject(
        Context $context,
        Value $typeKind,
        string $function,
        int $argIndex,
        string $paramName,
        string $expectedType = 'string'
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $longTy = $i8->constInt(VmVariable::TYPE_INTEGER & 0x7f, false);
        $doubleTy = $i8->constInt(VmVariable::TYPE_FLOAT & 0x7f, false);
        $boolTy = $i8->constInt(VmVariable::TYPE_BOOLEAN & 0x7f, false);

        $intErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_int');
        $afterInt = BasicBlockHelper::append($context, 'str_req_after_int');
        $floatErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_float');
        $afterFloat = BasicBlockHelper::append($context, 'str_req_after_float');
        $boolErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_bool');
        $mixedErrBlock = BasicBlockHelper::append($context, 'str_req_scalar_mixed');

        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeKind, $longTy);
        $context->builder->branchIf($isInt, $intErrBlock, $afterInt);

        $context->builder->positionAtEnd($intErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'int', $expectedType);

        $context->builder->positionAtEnd($afterInt);
        $isFloat = $context->builder->icmp(Builder::INT_EQ, $typeKind, $doubleTy);
        $context->builder->branchIf($isFloat, $floatErrBlock, $afterFloat);

        $context->builder->positionAtEnd($floatErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'float', $expectedType);

        $context->builder->positionAtEnd($afterFloat);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeKind, $boolTy);
        $context->builder->branchIf($isBool, $boolErrBlock, $mixedErrBlock);

        $context->builder->positionAtEnd($boolErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'bool', $expectedType);

        $context->builder->positionAtEnd($mixedErrBlock);
        self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'mixed', $expectedType);
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
        $ids = [];
        foreach ($jitObject->allDeclaredClassLowerNames() as $lc) {
            $declaredId = $jitObject->lookup($lc);
            $ids[] = [$declaredId, $jitObject->classNameForId($declaredId)];
        }
        if ([] === $ids) {
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, 'object', $expectedType);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $fn = BasicBlockHelper::parentFunction($context);
        $checkBlock = $context->builder->getInsertBlock();
        $okBlock = BasicBlockHelper::append($context, 'str_class_id_reject_ok');
        $lastIdx = \count($ids) - 1;
        foreach ($ids as $idx => [$declaredId, $className]) {
            $context->builder->positionAtEnd($checkBlock);
            $match = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($declaredId, false)
            );
            $rejectBlock = $fn->appendBasicBlock('str_class_id_reject_'.$declaredId);
            $nextBlock = $idx === $lastIdx
                ? $okBlock
                : $fn->appendBasicBlock('str_class_id_try_'.($idx + 1));
            $context->builder->branchIf($match, $rejectBlock, $nextBlock);
            $context->builder->positionAtEnd($rejectBlock);
            self::emitTypeErrorAndAbort($context, $function, $argIndex, $paramName, $className, $expectedType);
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
        ExceptionBridge::emitTypeErrorAndAbort(
            $context,
            self::typeErrorMessage($function, $argIndex, $paramName, $given, $expectedType)
        );
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

        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($loweredStr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $context->builder->not($empty),
            'str_empty',
            $errorMessage
        );
    }
}

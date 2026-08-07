<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for exit/die (issue #269). */
final class ScriptExit
{
    public static function emitWithMessage(Context $context, Variable $statusArg, Variable $messageArg): void
    {
        self::emitMessage($context, $messageArg);
        self::emitStatusOnly($context, $statusArg);
    }

    public static function emit(Context $context, Variable $arg): void
    {
        self::emitStatusOnly($context, $arg);
    }

    private static function emitStatusOnly(Context $context, Variable $arg): void
    {
        switch ($arg->type) {
            case Variable::TYPE_NULL:
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_STRING:
                self::echoString($context, $context->helper->loadValue($arg));
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_NATIVE_LONG:
                self::callLibcExit($context, $context->helper->loadValue($arg));
                break;
            case Variable::TYPE_NATIVE_DOUBLE:
                $doubleVal = $context->helper->loadValue($arg);
                $context->builder->call(
                    $context->lookupFunction('__phpc_ob_echo_double'),
                    $doubleVal
                );
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_NATIVE_BOOL:
                self::emitNativeBool($context, $context->helper->loadValue($arg));
                self::callLibcExit($context, $context->getTypeFromString('int64')->constInt(0, false));
                break;
            case Variable::TYPE_VALUE:
                self::emitBoxed($context, $context->helper->loadValue($arg));
                break;
            case Variable::TYPE_OBJECT:
                self::emitObjectStatus($context, $context->helper->loadValue($arg), $arg);
                break;
            case Variable::TYPE_HASHTABLE:
                // PHP 8.4+ exit()/die() string|int — array status TypeError (#22492).
                if (\PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
                    self::emitStatusTypeErrorAndAbort($context, 'array');

                    return;
                }
                throw new \LogicException('exit() only supports string or integer status in this compiler build');
            default:
                throw new \LogicException('exit() only supports string or integer status in this compiler build');
        }
    }

    private static function emitMessage(Context $context, Variable $arg): void
    {
        switch ($arg->type) {
            case Variable::TYPE_NULL:
                return;
            case Variable::TYPE_STRING:
                self::echoString($context, $context->helper->loadValue($arg));
                return;
            case Variable::TYPE_NATIVE_LONG:
                $context->builder->call(
                    $context->lookupFunction('__phpc_ob_echo_ll'),
                    $context->helper->loadValue($arg)
                );
                return;
            case Variable::TYPE_NATIVE_DOUBLE:
                $context->builder->call(
                    $context->lookupFunction('__phpc_ob_echo_double'),
                    $context->helper->loadValue($arg)
                );
                return;
            case Variable::TYPE_NATIVE_BOOL:
                self::emitNativeBool($context, $context->helper->loadValue($arg));
                return;
            case Variable::TYPE_VALUE:
                self::emitBoxedMessage($context, $context->helper->loadValue($arg));
                return;
            default:
                throw new \LogicException('exit() message must be string-coercible in this compiler build');
        }
    }

    private static function emitBoxedMessage(Context $context, Value $boxedPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($boxedPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');

        $nullBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_null');
        $stringBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_string');
        $longBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_long');
        $boolBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_double');
        $doneBlock = BasicBlockHelper::append($context, 'exit_msg_boxed_done');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $afterNull = BasicBlockHelper::append($context, 'exit_msg_boxed_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterStringProbe = BasicBlockHelper::append($context, 'exit_msg_boxed_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterStringProbe);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxedPtr
        );
        self::echoString($context, $strPtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterStringProbe);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'exit_msg_boxed_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_ll'),
            $longVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'exit_msg_boxed_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitNativeBool($context, $boolVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $context->builder->branchIf($isDouble, $doubleBlock, $doneBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $boxedPtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_double'),
            $doubleVal
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function emitBoxed(Context $context, Value $boxedPtr): void
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($boxedPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $nullBlock = BasicBlockHelper::append($context, 'exit_boxed_null');
        $stringBlock = BasicBlockHelper::append($context, 'exit_boxed_string');
        $longBlock = BasicBlockHelper::append($context, 'exit_boxed_long');
        $boolBlock = BasicBlockHelper::append($context, 'exit_boxed_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'exit_boxed_double');
        $badBlock = BasicBlockHelper::append($context, 'exit_boxed_bad');

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $afterNull = BasicBlockHelper::append($context, 'exit_boxed_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $afterStringProbe = BasicBlockHelper::append($context, 'exit_boxed_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterStringProbe);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxedPtr
        );
        self::echoString($context, $strPtr);
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterStringProbe);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'exit_boxed_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::callLibcExit($context, $longVal);

        $context->builder->positionAtEnd($afterLong);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'exit_boxed_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitNativeBool($context, $boolVal);
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterBool);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'exit_boxed_after_double');
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $boxedPtr
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_double'),
            $doubleVal
        );
        self::callLibcExit($context, $i64->constInt(0, false));

        $context->builder->positionAtEnd($afterDouble);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $enumCaseBlock = BasicBlockHelper::append($context, 'exit_boxed_enum_case');
        $afterEnumCase = BasicBlockHelper::append($context, 'exit_boxed_after_enum_case');
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $afterEnumCase);

        $context->builder->positionAtEnd($enumCaseBlock);
        self::emitBoxedEnumCaseError($context, $boxedPtr);
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($afterEnumCase);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $objectBlock = BasicBlockHelper::append($context, 'exit_boxed_object');
        $afterObject = BasicBlockHelper::append($context, 'exit_boxed_after_object');
        $context->builder->branchIf($isObject, $objectBlock, $afterObject);

        $context->builder->positionAtEnd($objectBlock);
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $boxedPtr
        );
        self::emitObjectStatus($context, $objPtr);

        $context->builder->positionAtEnd($afterObject);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_HASHTABLE, false)
        );
        $arrayBlock = BasicBlockHelper::append($context, 'exit_boxed_array');
        $context->builder->branchIf($isArray, $arrayBlock, $badBlock);

        $context->builder->positionAtEnd($arrayBlock);
        // PHP 8.4+ exit()/die() string|int — boxed array TypeError (#22492).
        if (\PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
            self::emitStatusTypeErrorAndAbort($context, 'array');
        } else {
            $context->builder->call(
                $context->lookupFunction('exit'),
                $i32->constInt(1, false)
            );
        }

        $context->builder->positionAtEnd($badBlock);
        $context->builder->call(
            $context->lookupFunction('exit'),
            $i32->constInt(1, false)
        );
    }

    private static function emitNativeBool(Context $context, Value $boolVal): void
    {
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolVal,
            $boolVal->typeOf()->constInt(0, false)
        );
        $trueBlock = BasicBlockHelper::append($context, 'exit_native_bool_true');
        $doneBlock = BasicBlockHelper::append($context, 'exit_native_bool_done');
        $context->builder->branchIf($isTrue, $trueBlock, $doneBlock);

        $context->builder->positionAtEnd($trueBlock);
        $charPtr = $context->getTypeFromString('char*');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_cstr'),
            $context->builder->pointerCast($context->constantFromString('1'), $charPtr)
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function echoString(Context $context, Value $strPtr): void
    {
        $offset = $context->structFieldIndex($strPtr, 'length');
        $length = $context->builder->load($context->builder->structGep($strPtr, $offset));
        $offset = $context->structFieldIndex($strPtr, 'value');
        $valuePtr = $context->builder->structGep($strPtr, $offset);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%.*s'),
            $context->getTypeFromString('char*')
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $fmt,
            $length,
            $valuePtr
        );
    }

    private static function callLibcExit(Context $context, Value $status): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            PendingHeaders::emitFlushForStandalone($context);
            ObOutput::emitEndAllForStandalone($context);
        }
        $i32 = $context->getTypeFromString('int32');
        $trunc = $context->builder->trunc($status, $i32);
        $context->builder->call($context->lookupFunction('exit'), $trunc);
    }

    public static function emitLibcExitWithStatus(Context $context, Value $status): void
    {
        self::callLibcExit($context, $status);
    }

    private static function emitObjectStatus(Context $context, Value $objPtr, ?Variable $arg = null): void
    {
        $enumClass = null !== $arg
            ? JitOperandTypeLabel::compileTimeEnumClassName($context, $arg)
            : null;
        if (null !== $enumClass) {
            // No ExitStatus builtin (#28500); any compile-time enum status → Error like Zend.
            self::emitEnumStringConversionError($context, $enumClass);
            $context->builder->call($context->lookupFunction('abort'));

            return;
        }
        $context->type->object->emitExitStatusObjectGuard($context, $objPtr);
    }

    private static function emitBoxedEnumCaseError(Context $context, Value $boxedPtr): void
    {
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitEnumStringConversionError($context, 'object');

            return;
        }
        $classIdVal = $context->builder->load(
            $context->builder->structGep($boxedPtr, $enumMap['class_id'])
        );
        if (method_exists($classIdVal, 'isConstant') && $classIdVal->isConstant()) {
            $classId = (int) $classIdVal->getConstantValue();
            self::emitEnumStringConversionError(
                $context,
                $context->type->object->classNameForId($classId)
            );

            return;
        }
        self::emitEnumStringConversionError($context, 'object');
    }

    private static function emitEnumStringConversionError(Context $context, string $className): void
    {
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise(
            $context,
            'Object of class '.$className.' could not be converted to string'
        );
    }

    public static function emitStatusTypeErrorAndAbort(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            \sprintf(
                'exit(): Argument #1 ($status) must be of type string|int, %s given',
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }
}

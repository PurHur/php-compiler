<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** Lower disk_*_space() path args (php-src filestat.c; #12619 null directory). */
final class JitDiskPath
{
    /** @return Value boxed float|false */
    public static function lowerDiskSpaceBoxed(
        Context $context,
        ?JITVariable $arg,
        string $function,
        bool $freeSpace
    ): Value {
        if (null === $arg) {
            return self::nullDirectoryFailureBoxed($context, $function);
        }
        if (JITVariable::TYPE_NULL === $arg->type) {
            if ($context->callerStrictTypes) {
                self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'null'));
            }

            return self::nullDirectoryFailureBoxed($context, $function);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedDiskSpace($context, $arg, $function, $freeSpace);
        }
        $path = self::lowerPathString($context, $arg, $function);

        return $freeSpace
            ? JitStat::pathDiskFreeSpaceBoxed($context, $path)
            : JitStat::pathDiskTotalSpaceBoxed($context, $path);
    }

    /** @return Value */
    private static function lowerPathString(Context $context, JITVariable $arg, string $function): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'array'));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'object'));

            return self::unreachableStringPtr($context);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedPathString($context, $arg, $function);
        }
        if ($context->callerStrictTypes) {
            JitInternalStrictArg::requireString($context, $arg, $function, 'directory', 1);
        }

        return JitStringArg::lower($context, $arg, $function.'() directory');
    }

    /** @return Value boxed float|false */
    private static function lowerBoxedDiskSpace(
        Context $context,
        JITVariable $arg,
        string $function,
        bool $freeSpace
    ): Value {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $nullTy = $i8->constInt(VmVariable::TYPE_NULL, false);
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);

        $nullBlock = BasicBlockHelper::append($context, 'diskspace_null');
        $arrayBlock = BasicBlockHelper::append($context, 'diskspace_array');
        $statBlock = BasicBlockHelper::append($context, 'diskspace_stat');
        $mergeBlock = BasicBlockHelper::append($context, 'diskspace_merge');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTy);
        $context->builder->branchIf($isNull, $nullBlock, $arrayBlock);

        $context->builder->positionAtEnd($nullBlock);
        if ($context->callerStrictTypes) {
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'null'));
        }
        $nullResult = self::nullDirectoryFailureBoxed($context, $function);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($arrayBlock);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $arrayErrBlock = BasicBlockHelper::append($context, 'diskspace_array_err');
        $afterArrayBlock = BasicBlockHelper::append($context, 'diskspace_after_array');
        $context->builder->branchIf($isArray, $arrayErrBlock, $afterArrayBlock);
        $context->builder->positionAtEnd($arrayErrBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'array'));

        $context->builder->positionAtEnd($afterArrayBlock);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $objectErrBlock = BasicBlockHelper::append($context, 'diskspace_object_err');
        $stringBlock = BasicBlockHelper::append($context, 'diskspace_string');
        $context->builder->branchIf($isObject, $objectErrBlock, $stringBlock);
        $context->builder->positionAtEnd($objectErrBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'object'));

        $context->builder->positionAtEnd($stringBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $strictErrBlock = BasicBlockHelper::append($context, 'diskspace_strict_err');
            $strictOkBlock = BasicBlockHelper::append($context, 'diskspace_strict_ok');
            $context->builder->branchIf($isString, $strictOkBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'mixed'));
            $context->builder->positionAtEnd($strictOkBlock);
        }
        $path = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $context->builder->branch($statBlock);

        $context->builder->positionAtEnd($statBlock);
        $statResult = $freeSpace
            ? JitStat::pathDiskFreeSpaceBoxed($context, $path)
            : JitStat::pathDiskTotalSpaceBoxed($context, $path);
        $statEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($nullResult->typeOf());
        $phi->addIncoming($nullResult, $nullEnd);
        $phi->addIncoming($statResult, $statEnd);

        return $phi;
    }

    /** @return Value */
    private static function lowerBoxedPathString(Context $context, JITVariable $arg, string $function): Value
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $arrayTy = $i8->constInt(VmVariable::TYPE_ARRAY, false);
        $objectTy = $i8->constInt(VmVariable::TYPE_OBJECT, false);

        $okBlock = BasicBlockHelper::append($context, 'diskpath_ok');
        $arrayBlock = BasicBlockHelper::append($context, 'diskpath_array');
        $objectBlock = BasicBlockHelper::append($context, 'diskpath_object');
        $strictBlock = BasicBlockHelper::append($context, 'diskpath_strict');

        $isArray = $context->builder->icmp(Builder::INT_EQ, $typeByte, $arrayTy);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTy);
        $context->builder->branchIf($isArray, $arrayBlock, $okBlock);

        $context->builder->positionAtEnd($arrayBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'array'));

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branchIf($isObject, $objectBlock, $strictBlock);

        $context->builder->positionAtEnd($objectBlock);
        self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'object'));

        $context->builder->positionAtEnd($strictBlock);
        if ($context->callerStrictTypes) {
            $isString = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(VmVariable::TYPE_STRING, false)
            );
            $coerceBlock = BasicBlockHelper::append($context, 'diskpath_coerce');
            $strictErrBlock = BasicBlockHelper::append($context, 'diskpath_strict_err');
            $context->builder->branchIf($isString, $coerceBlock, $strictErrBlock);
            $context->builder->positionAtEnd($strictErrBlock);
            self::emitTypeErrorAndAbort($context, self::typeErrorMessage($function, 'mixed'));
            $context->builder->positionAtEnd($coerceBlock);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
    }

    /** @return Value boxed false */
    private static function nullDirectoryFailureBoxed(Context $context, string $function): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        // php-src filestat.c — null directory returns false without warning (#12619, #12788).
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return $ptr;
    }

    private static function typeErrorMessage(string $function, string $given): string
    {
        return \sprintf(
            '%s(): Argument #1 ($directory) must be of type string, %s given',
            $function,
            $given
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function unreachableStringPtr(Context $context): Value
    {
        return $context->getTypeFromString('__string__*')->constNull();
    }
}

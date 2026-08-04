<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\Builtin\ErrorRaise;

/**
 * Compile-time by-ref actual checks for internal calls (Zend ZEND_SEND_REF subset).
 */
final class JitReferencableCheck
{
    public static function isOperandReferenceable(?Operand $operand, Variable $arg): bool
    {
        if (null !== $operand && null !== OperandName::resolve($operand)) {
            return true;
        }
        if (null !== $arg->objectPropertySlot) {
            return true;
        }
        if (null !== $arg->valueBoxAliasPtr) {
            return true;
        }

        return false;
    }

    public static function isEphemeralArrayArg(Variable $arg): bool
    {
        if (null !== $arg->objectPropertySlot || null !== $arg->valueBoxAliasPtr) {
            return false;
        }

        if (Variable::TYPE_HASHTABLE === $arg->type
            || 0 !== ($arg->type & Variable::IS_NATIVE_ARRAY)
        ) {
            return true;
        }

        return Variable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg);
    }

    public static function emitByRefError(Context $context, string $fn, int $paramIdx): void
    {
        $paramNames = BuiltinParamNames::forFunction($fn) ?? [];
        $paramName = $paramNames[$paramIdx] ?? 'param'.($paramIdx + 1);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, \sprintf(
            '%s(): Argument #%d ($%s) cannot be passed by reference',
            $fn,
            $paramIdx + 1,
            $paramName
        ));
    }

    /**
     * @return bool false when a pending Error was registered (caller should not mutate)
     */
    public static function guardArrayMutatorByRefArg(Context $context, string $fn, Variable $array): bool
    {
        if (!self::isEphemeralArrayArg($array)) {
            return true;
        }
        if (\PHPCompiler\VM\ReferencableCheck::allowsEphemeralArrayLiteralByRef($fn)) {
            return true;
        }
        // Call/method return temps: adaptByRefCallArgsForInternal already emitted E_NOTICE (#25815).
        if (
            \PHPCompiler\VM\ReferencableCheck::isArrayInternalPointerMutatorBuiltin($fn)
            && !empty($array->nonVariableByRefTempAllowed)
        ) {
            return true;
        }
        // Pointer mutators (reset/next/prev/end): named TYPE_VALUE locals are over-classified as
        // ephemeral by isEphemeralArrayArg (#27484). True inline literals already Error+abort in
        // adaptByRefCallArgsForInternal — do not emit a second abort here (that poisoned $a lvalues).
        if (\PHPCompiler\VM\ReferencableCheck::isArrayInternalPointerMutatorBuiltin($fn)) {
            return true;
        }
        self::emitByRefError($context, $fn, 0);
        $context->builder->call($context->lookupFunction('abort'));

        return false;
    }

    public static function emitNonVariableByRefNotice(Context $context): void
    {
        $message = \PHPCompiler\VM\ReferencableCheck::NON_VARIABLE_BY_REF_NOTICE_MESSAGE;
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}

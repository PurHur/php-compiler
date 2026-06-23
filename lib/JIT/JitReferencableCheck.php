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

    /** @return bool false when a pending Error was registered (caller should not mutate) */
    public static function guardArrayMutatorByRefArg(Context $context, string $fn, Variable $array): bool
    {
        if (!self::isEphemeralArrayArg($array)) {
            return true;
        }
        if (\PHPCompiler\VM\ReferencableCheck::allowsEphemeralArrayLiteralByRef($fn)) {
            return true;
        }
        self::emitByRefError($context, $fn, 0);
        $context->builder->call($context->lookupFunction('abort'));

        return false;
    }
}

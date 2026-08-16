<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Compile-time str_pad() / mb_str_pad() $pad_type lowering (#7282, #27435, #28201).
 *
 * Accepts named STR_PAD_* constants and LLVM/int literals (AOT often folds
 * STR_PAD_BOTH to the integer 2 without compileTimeConstantName).
 * PadType enum cases are phantoms vs php-src — not registered (#28201).
 */
final class PadTypeJit
{
    public static function compileTimePadType(Context $context, JITVariable $arg): ?int
    {
        $fromName = self::compileTimeNamedPadType($context, $arg);
        if (null !== $fromName) {
            return $fromName;
        }

        return self::compileTimeIntPadType($context, $arg);
    }

    private static function compileTimeNamedPadType(Context $context, JITVariable $arg): ?int
    {
        if (null === $arg->compileTimeConstantName) {
            return null;
        }
        $lookup = strtolower($arg->compileTimeConstantName);
        $stdlibInt = StdlibConstants::coreIntByName($lookup);
        if (null !== $stdlibInt && self::isValidPadType($stdlibInt)) {
            return $stdlibInt;
        }
        if (null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
        if (null === $phpVar) {
            return null;
        }
        $fromEnum = VmString::tryPadTypeInt($phpVar);
        if (null !== $fromEnum) {
            return $fromEnum;
        }
        if (\PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
            $value = $phpVar->toInt();
            if (self::isValidPadType($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function compileTimeIntPadType(Context $context, JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeLong && self::isValidPadType($arg->compileTimeLong)) {
            return $arg->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (null === $arg->value) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }
        $value = (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
        if (!self::isValidPadType($value)) {
            return null;
        }

        return $value;
    }

    private static function isValidPadType(int $padType): bool
    {
        return \in_array($padType, [
            StdlibConstants::STR_PAD_LEFT,
            StdlibConstants::STR_PAD_RIGHT,
            StdlibConstants::STR_PAD_BOTH,
        ], true);
    }
}

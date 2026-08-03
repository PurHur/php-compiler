<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrpos;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strpos() for two strings (subset of PHP; non-empty needle, Zend offset window).
 */
final class strpos extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#21964).
        $this->requireArgCountRange($frame, 'strpos', 2, 3);
        $argc = count($frame->calledArgs);
        $haystackStr = self::vmStringArg($frame, 0, 'haystack');
        $needleStr = self::vmStringArg($frame, 1, 'needle');
        if (null === $frame->returnVar) {
            return;
        }
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'strpos', 3, 'offset');
        }
        $result = VmString::strpos($haystackStr, $needleStr, $offset);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireArgCountRangeJit($context, $args, 'strpos', 2, 3)) {
            return StringStrpos::boxIntOrFalse($context, false);
        }
        $argc = count($args);
        $hayLit = JitStringArg::compileTimeLiteral($args[0]);
        $needleLit = JitStringArg::compileTimeLiteral($args[1]);
        $offsetLit = 3 === $argc ? self::tryCompileTimeInt($context, $args[2]) : 0;
        if (null !== $hayLit && null !== $needleLit && null !== $offsetLit) {
            $pos = VmString::strpos($hayLit, $needleLit, $offsetLit);

            return StringStrpos::boxIntOrFalse($context, $pos);
        }

        StringStrpos::ensureLinked($context);
        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21189).
        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strpos', 0, 'haystack')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strpos', 0, 'haystack');
        $needle = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'strpos', 1, 'needle')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'strpos', 1, 'needle');
        $offset = 3 === $argc
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'strpos', 3, 'offset')
            : null;

        return StringStrpos::invoke($context, $hay, $needle, $offset);
    }

    private static function tryCompileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return VmMath::floatToZendLong((float) $const->constDouble());
            }
        }

        return null;
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strpos', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21189; reverts #19242/#20176).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strpos',
            $argIndex,
            $paramName
        );
    }
}

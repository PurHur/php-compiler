<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrrpos;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strripos() for two strings (subset of PHP; ASCII case fold, non-empty needle, Zend offset window).
 */
final class strripos extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28311).
        $this->requireArgCountRange($frame, 'strripos', 2, 3);
        $argc = count($frame->calledArgs);
        $haystackStr = self::vmStringArg($frame, 0, 'haystack');
        $needleStr = self::vmStringArg($frame, 1, 'needle');
        if (null === $frame->returnVar) {
            return;
        }
        $offset = 0;
        if (3 === $argc) {
            $offset = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'strripos', 3, 'offset');
        }
        $result = VmString::strripos($haystackStr, $needleStr, $offset);
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
        // Catchable ArgumentCountError (AOT) — peer strpos #21964 / #28311.
        if (!$this->requireArgCountRangeJit($context, $args, 'strripos', 2, 3)) {
            return StringStrrpos::boxIntOrFalse($context, false);
        }
        $argc = count($args);
        $hayLit = JitStringArg::compileTimeLiteral($args[0]);
        $needleLit = JitStringArg::compileTimeLiteral($args[1]);
        $offsetLit = 3 === $argc ? self::tryCompileTimeInt($context, $args[2]) : 0;
        if (null !== $hayLit && null !== $needleLit && null !== $offsetLit) {
            $pos = VmString::strripos($hayLit, $needleLit, $offsetLit);

            return StringStrrpos::boxIntOrFalse($context, $pos);
        }
        StringStrrpos::ensureLinked($context);
        $hay = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strripos', 0, 'haystack')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strripos', 0, 'haystack');
        $needle = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'strripos', 1, 'needle')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[1], 'strripos', 1, 'needle');
        $offset = 3 === $argc
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'strripos', 3, 'offset')
            : null;

        return StringStrrpos::invoke($context, $hay, $needle, $offset, true);
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
            return InternalStrictArg::requireString($frame, $argIndex, 'strripos', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strripos',
            $argIndex,
            $paramName
        );
    }
}

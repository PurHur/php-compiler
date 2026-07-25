<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringWordwrap;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * wordwrap() — wrap string to width (subset of PHP).
 *
 * VM: {@see VmString::wordwrap()}; JIT/AOT: {@see StringWordwrap} + {@see WordwrapJitHelper}.
 */
final class wordwrap extends Internal
{
    public function __construct()
    {
        parent::__construct('wordwrap');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'wordwrap', 1, 4);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $text = self::vmStringArg($frame, 0, 'string');
        $width = 75;
        if ($argc >= 2) {
            $width = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'wordwrap', 2, 'width');
        }
        $break = "\n";
        if ($argc >= 3) {
            $break = VmString::coerceZparamStrBuiltinArg(
                $frame->calledArgs[2],
                'wordwrap',
                2,
                'break'
            );
        }
        $cut = false;
        if (4 === $argc) {
            $c = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $c->type) {
                throw new \LogicException('wordwrap() cut must be a boolean in this compiler build');
            }
            $cut = $c->toBool();
        }
        $frame->returnVar->string(
            VmString::wordwrap($text, $width, $break, $cut)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'wordwrap', 1, 4)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $width = self::compileTimeWidth($args, $argc);
            $break = self::compileTimeBreak($args, $argc);
            $cut = self::compileTimeCut($context, $args, $argc);
            if (null !== $width && null !== $break) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::wordwrap($literal, $width, $break, $cut))
                );
            }
        }

        $i64 = $context->getTypeFromString('int64');
        $width = $i64->constInt(75, false);
        if ($argc >= 2) {
            $width = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'wordwrap', 2, 'width');
        }
        if ($argc >= 3) {
            $break = JitStringBuiltinArg::lowerZparamStr($context, $args[2], 'wordwrap', 2, 'break');
        } else {
            $break = $context->builder->load($context->constantStringFromString("\n"));
        }
        $i8 = $context->getTypeFromString('int8');
        $cutI8 = $i8->constInt(0, false);
        if (4 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[3]->type) {
                throw new \LogicException('wordwrap() cut must be a boolean in this compiler build');
            }
            $cutI8 = $context->builder->zExt($context->helper->loadValue($args[3]), $i8);
        }

        $text = self::jitStringArg($context, $args[0], 0, 'string');
        StringWordwrap::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_wordwrap'),
            $text,
            $width,
            $break,
            $cutI8
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'wordwrap', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21190).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'wordwrap',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'wordwrap',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'wordwrap',
            $argIndex,
            $paramName
        );
    }

    private static function compileTimeWidth(array $args, int $argc): ?int
    {
        if ($argc < 2) {
            return 75;
        }

        return self::compileTimeInt($args[1], null);
    }

    private static function compileTimeBreak(array $args, int $argc): ?string
    {
        if ($argc < 3) {
            return "\n";
        }

        return JitStringArg::compileTimeLiteral($args[2]);
    }

    private static function compileTimeCut(Context $context, array $args, int $argc): bool
    {
        if ($argc < 4) {
            return false;
        }
        if (JITVariable::TYPE_NATIVE_BOOL !== $args[3]->type || JITVariable::KIND_VALUE !== $args[3]->kind) {
            return false;
        }
        $raw = $args[3]->value->value ?? null;
        if (null === $raw) {
            return false;
        }

        return 0 !== (int) $context->llvm->lib->LLVMConstIntGetZExtValue($raw);
    }

    private static function compileTimeInt(JITVariable $arg, ?int $default): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return (int) $const->constInt();
            }
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $const = $arg->value;
            if ($const instanceof Value && $const->isConstant()) {
                return VmMath::floatToZendLong((float) $const->constDouble());
            }
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal && '' !== $literal && is_numeric($literal)) {
            return (int) $literal;
        }

        return $default;
    }
}

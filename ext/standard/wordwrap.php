<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringWordwrap;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
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
        if (null === $frame->returnVar) {
            return;
        }
        // Named skips (e.g. break: without width) leave sparse calledArgs — use isset, not argc (#28938).
        $text = self::vmStringArg($frame, 0, 'string');
        $width = 75;
        if (isset($frame->calledArgs[1])) {
            $width = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'wordwrap', 2, 'width');
        }
        $break = "\n";
        if (isset($frame->calledArgs[2])) {
            $break = VmString::coerceZparamStrBuiltinArg(
                $frame->calledArgs[2],
                'wordwrap',
                2,
                'break'
            );
        }
        $cut = false;
        if (isset($frame->calledArgs[3])) {
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
        $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            $width = self::compileTimeWidth($args);
            $break = self::compileTimeBreak($args);
            $cut = self::compileTimeCut($context, $args);
            if (null !== $width && null !== $break) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::wordwrap($literal, $width, $break, $cut))
                );
            }
        }

        $i64 = $context->getTypeFromString('int64');
        $width = $i64->constInt(75, false);
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $width = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'wordwrap', 2, 'width');
        }
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $break = JitStringBuiltinArg::lowerZparamStr($context, $args[2], 'wordwrap', 2, 'break');
        } else {
            $break = $context->builder->load($context->constantStringFromString("\n"));
        }
        $i8 = $context->getTypeFromString('int8');
        $cutI8 = $i8->constInt(0, false);
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
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

    private static function compileTimeWidth(array $args): ?int
    {
        if (!isset($args[1]) || NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            return 75;
        }

        return self::compileTimeInt($args[1], null);
    }

    private static function compileTimeBreak(array $args): ?string
    {
        if (!isset($args[2]) || NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            return "\n";
        }

        return JitStringArg::compileTimeLiteral($args[2]);
    }

    private static function compileTimeCut(Context $context, array $args): bool
    {
        if (!isset($args[3]) || NamedOptionalCallArgs::isOmittedOptional($args[3])) {
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

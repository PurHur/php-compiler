<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MathAbs;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class abs extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'abs', 1);
        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'abs',
            1,
            'num',
            $frame
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (\is_int($num)) {
            if ($num < 0) {
                $abs = -$num;
                if (\is_float($abs)) {
                    $frame->returnVar->float($abs);

                    return;
                }
                $frame->returnVar->int($abs);

                return;
            }
            $frame->returnVar->int($num);

            return;
        }
        // php-src math.c fabs: abs(-0.0) → +0.0 (#23978). `$num < 0.0` is false for -0.0.
        $frame->returnVar->float(AbsJitHelper::absDoubleArgv($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'abs', 1)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $args[0]->type) {
            $v = $context->helper->loadValue($args[0]);

            return MathAbs::invokeDouble($context, $v);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            if (JITVariable::KIND_VALUE === $args[0]->kind) {
                $const = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($args[0]->value->value);
                if (\PHP_INT_MIN === $const) {
                    return $context->getTypeFromString('double')->constReal(-(float) $const);
                }
            }
            $v = JitLongArg::lower($context, $args[0], 'abs() argument #1');

            return MathAbs::invokeLong($context, $v);
        }
        $asFloat = JitMathNumberArg::lowerToDouble($context, $args[0], 'abs', 1, 'num');

        return MathAbs::invokeDouble($context, $asFloat);
    }
}

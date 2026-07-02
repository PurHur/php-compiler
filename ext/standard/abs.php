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
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('Expecting exactly one argument to abs()');
        }
        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'abs',
            1,
            'num'
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
        $frame->returnVar->float($num < 0.0 ? -$num : $num);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('Expecting exactly one argument to abs()');
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

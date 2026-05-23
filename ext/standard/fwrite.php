<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fwrite() — VM via VmFs; JIT/AOT via __compiler_fwrite (issue #1070). */
final class fwrite extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fwrite() requires two or three arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fwrite() handle must be an integer in this compiler build');
        }
        if (Variable::TYPE_STRING !== $dataVar->type) {
            throw new \LogicException('fwrite() data must be a string in this compiler build');
        }
        $length = null;
        if (3 === $argc) {
            $lenVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('fwrite() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        $written = VmFs::fwrite($handleVar->toInt(), $dataVar->toString(), $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('fwrite() requires two or three arguments in this compiler build');
        }
        $handle = JitLongArg::lower($context, $args[0], 'fwrite() handle');
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('fwrite() data must be a string in this compiler build');
        }
        $data = $context->helper->loadValue($args[1]);
        $length = $context->getTypeFromString('int64')->constInt(-1, false);
        if (3 === $argc) {
            $length = JitLongArg::lower($context, $args[2], 'fwrite() length');
        }

        return JitFwrite::invoke($context, $handle, $data, $length);
    }
}

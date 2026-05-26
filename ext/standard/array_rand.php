<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_rand() — random key(s) from packed list arrays (subset of PHP; issue #2321).
 *
 * VM: packed lists without holes; CSPRNG via {@see VmString::randomBytes()}.
 * JIT/AOT: {@see JitArrayRand::randPacked()}.
 */
final class array_rand extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() requires one or two arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_rand() argument #1 must be an array in this compiler build');
        }
        $num = 1;
        if (2 === $argc) {
            $numArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $numArg->type) {
                throw new \LogicException('array_rand() argument #2 must be an integer in this compiler build');
            }
            $num = $numArg->toInt();
        }
        $frame->returnVar->copyFrom(VmArray::randPackedKeys($array->toArray(), $num));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('array_rand() requires one or two arguments');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_rand() argument #'.((int) $i + 1));
            }
        }
        $num = $context->getTypeFromString('size_t')->constInt(1, false);
        if (2 === \count($args)) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('array_rand() argument #2 must be an integer in this compiler build');
            }
            $num = JitLongArg::lower($context, $args[1], 'array_rand() argument #2');
        }

        return JitArrayRand::randPacked($context, $args[0], $num);
    }
}

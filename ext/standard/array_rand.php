<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_rand() — random key(s) from a packed list (subset of PHP; issue #2321).
 *
 * VM: packed lists without holes; CSPRNG via {@see VmString::randomBytes()}.
 * JIT/AOT: {@see JitArrayRand} (single-key only; num>1 is VM-only).
 */
final class array_rand extends Internal
{
    public function __construct()
    {
        parent::__construct('array_rand');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_rand() accepts one or two arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
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
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmArray::arrayRandPacked($array->toArray(), $num));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitArrayRand::call($context, ...$args);
    }
}

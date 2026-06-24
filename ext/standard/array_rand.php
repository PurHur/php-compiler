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
 * array_rand() — random key(s) from an array (issue #2321, #4460).
 *
 * VM: returns actual keys (string or int); CSPRNG via {@see VmString::randomBytes()}.
 * JIT/AOT: {@see JitArrayRand} (packed lists; num>1 returns array of keys).
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
            $num = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_rand', 2, 'num');
        }
        $result = VmArray::arrayRandPacked($array->toArray(), $num);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitArrayRand::call($context, ...$args);
    }
}

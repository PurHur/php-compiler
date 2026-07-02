<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mhash() — legacy binary digest (php-src ext/hash/hash.c; #14975). */
final class mhash extends Internal
{
    public function __construct()
    {
        parent::__construct('mhash');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('mhash() expects exactly 2 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algorithm = VmMhash::coerceAlgorithmArg($frame->calledArgs[0], 'mhash', 0, 'algo');
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'mhash', 1, 'data');
        $result = VmMhash::mhash($algorithm, $data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #14975)');
    }
}

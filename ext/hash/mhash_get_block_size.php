<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mhash_get_block_size() — mhash algorithm block size (php-src ext/hash/hash.c; #14975). */
final class mhash_get_block_size extends Internal
{
    public function __construct()
    {
        parent::__construct('mhash_get_block_size');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('mhash_get_block_size() expects exactly 1 argument, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algorithm = VmMhash::coerceAlgorithmArg($frame->calledArgs[0], 'mhash_get_block_size', 0, 'algo');
        $result = VmMhash::getBlockSize($algorithm);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT in this compiler build (issue #14975)');
    }
}

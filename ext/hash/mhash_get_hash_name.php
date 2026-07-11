<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** mhash_get_hash_name() — mhash algorithm display name (php-src ext/hash/hash.c; #14975). */
final class mhash_get_hash_name extends Internal
{
    public function __construct()
    {
        parent::__construct('mhash_get_hash_name');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('mhash_get_hash_name() expects exactly 1 argument, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algorithm = VmMhash::coerceAlgorithmArg($frame->calledArgs[0], 'mhash_get_hash_name', 0, 'algo');
        $result = VmMhash::getHashName($algorithm);
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

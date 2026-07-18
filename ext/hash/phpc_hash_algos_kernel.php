<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHash;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal registry kernel for HashAlgosJitHelper (#20652).
 *
 * Avoids NestedJIT of {@see VmHash::algos()} HashTable::append under user-script AOT.
 */
final class phpc_hash_algos_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_hash_algos_kernel');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_hash_algos_kernel() expects exactly 0 arguments');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmHash::algos());
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('phpc_hash_algos_kernel() expects exactly 0 arguments');
        }

        return JitHashAlgosKernel::invokeAlgos($context);
    }
}

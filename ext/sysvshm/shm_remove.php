<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shm_remove() — destroy SysV shared memory segment (php-src ext/sysvshm/sysvshm.c; #21635). */
final class shm_remove extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_remove');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shm_remove() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_remove');
        $object = SysvShmArgs::parseShm($frame, 'shm_remove');
        $host = SysvShmArgs::requireHost($object, 'shm_remove');
        $frame->returnVar->bool(VmSysvShm::remove($host));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_remove() is not supported for JIT/AOT in this compiler build (issue #21635)'
        );
    }
}

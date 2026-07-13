<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** shm_detach() — detach from shared memory segment (php-src ext/sysvshm/sysvshm.c; #6436). */
final class shm_detach extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_detach');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shm_detach() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_detach');
        $object = SysvShmArgs::parseShm($frame, 'shm_detach');
        $host = SysvShmArgs::requireHost($object, 'shm_detach');
        $ok = VmSysvShm::detach($host, $object);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_detach() is not supported for JIT/AOT in this compiler build (issue #6436)'
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shm_remove_var() — remove variable from shared memory (php-src ext/sysvshm/sysvshm.c; #6436). */
final class shm_remove_var extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_remove_var');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shm_remove_var() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_remove_var');
        $object = SysvShmArgs::parseShm($frame, 'shm_remove_var');
        $host = SysvShmArgs::requireHost($object, 'shm_remove_var');
        $key = SysvShmArgs::parseKey($frame, 'shm_remove_var', 1);

        $ok = VmSysvShm::removeVar($host, $key);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_remove_var() is not supported for JIT/AOT in this compiler build (issue #6436)'
        );
    }
}

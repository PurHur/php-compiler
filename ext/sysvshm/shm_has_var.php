<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shm_has_var() — check if key exists in shared memory (php-src ext/sysvshm/sysvshm.c; #21634). */
final class shm_has_var extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_has_var');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shm_has_var() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_has_var');
        $object = SysvShmArgs::parseShm($frame, 'shm_has_var');
        $host = SysvShmArgs::requireHost($object, 'shm_has_var');
        $key = SysvShmArgs::parseKey($frame, 'shm_has_var', 1);

        $frame->returnVar->bool(VmSysvShm::hasVar($host, $key));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_has_var() is not supported for JIT/AOT in this compiler build (issue #21634)'
        );
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shm_get_var() — read variable from shared memory (php-src ext/sysvshm/sysvshm.c; #6436). */
final class shm_get_var extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_get_var');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shm_get_var() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_get_var');
        $object = SysvShmArgs::parseShm($frame, 'shm_get_var');
        $host = SysvShmArgs::requireHost($object, 'shm_get_var');
        $key = SysvShmArgs::parseKey($frame, 'shm_get_var', 1);

        $value = VmSysvShm::getVar($host, $key);
        if (false === $value) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom(VmJson::import($value));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_get_var() is not supported for JIT/AOT in this compiler build (issue #6436)'
        );
    }
}

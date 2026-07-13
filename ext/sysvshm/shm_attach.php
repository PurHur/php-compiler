<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shm_attach() — attach System V shared memory segment (php-src ext/sysvshm/sysvshm.c; #6436). */
final class shm_attach extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_attach');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'shm_attach() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_attach');
        $key = SysvShmArgs::parseKey($frame, 'shm_attach');
        $memsize = SysvShmArgs::parseOptionalInt($frame, 1, 'shm_attach', 'size');
        $perm = SysvShmArgs::parseOptionalInt($frame, 2, 'shm_attach', 'permissions');

        [$result, , ] = VmSysvShm::attach($frame->vmContext, $key, $memsize, $perm);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_attach() is not supported for JIT/AOT in this compiler build (issue #6436)'
        );
    }
}

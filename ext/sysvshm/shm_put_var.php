<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shm_put_var() — store variable in shared memory (php-src ext/sysvshm/sysvshm.c; #6436). */
final class shm_put_var extends Internal
{
    public function __construct()
    {
        parent::__construct('shm_put_var');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shm_put_var() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        SysvShmArgs::requireAvailable('shm_put_var');
        $object = SysvShmArgs::parseShm($frame, 'shm_put_var');
        $host = SysvShmArgs::requireHost($object, 'shm_put_var');
        $key = SysvShmArgs::parseKey($frame, 'shm_put_var', 1);
        $value = VmHttpBuildQuery::export($frame->calledArgs[2], $frame);

        $ok = VmSysvShm::putVar($host, $key, $value);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shm_put_var() is not supported for JIT/AOT in this compiler build (issue #6436)'
        );
    }
}

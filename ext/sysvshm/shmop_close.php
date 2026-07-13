<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** shmop_close() — close shared memory segment handle (php-src ext/sysvshm/shmop.c; #3344). */
final class shmop_close extends Internal
{
    public function __construct()
    {
        parent::__construct('shmop_close');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shmop_close() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        ShmopArgs::requireAvailable('shmop_close');
        $object = ShmopArgs::parseShmop($frame, 'shmop_close');
        $host = ShmopArgs::requireHost($object, 'shmop_close');
        VmShmop::close($host, $object);
        $frame->returnVar->null();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'shmop_close() is not supported for JIT/AOT in this compiler build (issue #3344)'
        );
    }
}

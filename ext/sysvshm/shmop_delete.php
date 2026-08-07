<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/** shmop_delete() — mark shared memory segment for deletion (php-src ext/sysvshm/shmop.c; #3344). */
final class shmop_delete extends Internal
{
    public function __construct()
    {
        parent::__construct('shmop_delete');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'shmop_delete() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        ShmopArgs::requireAvailable('shmop_delete');
        $object = ShmopArgs::parseShmop($frame, 'shmop_delete');

        $ok = VmShmop::deleteForObject($object);
        if (!$ok) {
            $this->triggerWarning($frame, 'shmop_delete() failed');
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return JitShmopDelete::emitArgumentCountError($context, $argc);
        }

        return JitShmopDelete::invoke($context, $args[0]);
    }

    private function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}

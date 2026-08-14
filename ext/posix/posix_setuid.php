<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** posix_setuid() — set real user ID (VM VmPosix; JIT/AOT PosixSetuidJitHelper via PosixSetuidJit, #31038/#7376). */
final class posix_setuid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_setuid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_setuid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $uid = InternalStrictArg::requireInt($frame, 0, 'posix_setuid', 'user_id')->toInt();
        $frame->returnVar->bool(VmPosix::setuid($uid));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_setuid() expects exactly 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        JitInternalStrictArg::requireInt($context, $args[0], 'posix_setuid', 'user_id', 1);

        return JitPosix::setuid(
            $context,
            JitLongArg::lower($context, $args[0], 'posix_setuid() user_id')
        );
    }
}

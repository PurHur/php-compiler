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

/** posix_setgid() — set real group ID (VM VmPosix; JIT/AOT PosixSetgidJitHelper via PosixSetgidJit, #31066/#7376). */
final class posix_setgid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_setgid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_setgid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $gid = InternalStrictArg::requireInt($frame, 0, 'posix_setgid', 'gid')->toInt();
        $frame->returnVar->bool(VmPosix::setgid($gid));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_setgid() expects exactly 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        JitInternalStrictArg::requireInt($context, $args[0], 'posix_setgid', 'gid', 1);

        return JitPosix::setgid(
            $context,
            JitLongArg::lower($context, $args[0], 'posix_setgid() gid')
        );
    }
}

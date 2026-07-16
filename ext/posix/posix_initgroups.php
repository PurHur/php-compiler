<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** posix_initgroups() — initgroups(3) (php-src ext/posix/posix.c; #19476). */
final class posix_initgroups extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_initgroups');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'posix_initgroups() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $username = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'posix_initgroups',
            0,
            'username'
        );
        $groupId = VmPosix::coerceIntArg($frame->calledArgs[1], 'posix_initgroups', 1, 'group_id');
        $frame->returnVar->bool(VmPosix::initgroups($username, $groupId));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_initgroups() expects exactly 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        throw new \Error('posix_initgroups() is not implemented for JIT in this compiler build (issue #19476)');
    }
}

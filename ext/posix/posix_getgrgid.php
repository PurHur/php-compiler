<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** posix_getgrgid() — group entry by gid (php-src ext/posix/posix.c; #6489). */
final class posix_getgrgid extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_getgrgid');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_getgrgid() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $gid = InternalStrictArg::requireInt($frame, 0, 'posix_getgrgid', 'group_id')->toInt();
        $entry = VmPosix::getgrgid($gid);
        if (false === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmPosix::groupToHashTable($entry));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_getgrgid() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        throw new \Error('posix_getgrgid() is not implemented for JIT in this compiler build (issue #6489)');
    }
}

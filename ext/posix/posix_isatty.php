<?php

declare(strict_types=1);

namespace PHPCompiler\ext\posix;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** posix_isatty() — whether fd refers to a terminal (php-src ext/posix/posix.c; #6504). */
final class posix_isatty extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_isatty');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_isatty() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fd = VmPosix::resolveFileDescriptorArg($frame->calledArgs[0], 'posix_isatty', 0);
        if (null === $fd) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmPosix::isatty($fd));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'posix_isatty() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitPosix::isatty($context, $args[0]);
    }
}

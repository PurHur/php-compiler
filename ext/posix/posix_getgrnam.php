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
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** posix_getgrnam() — group entry by name (php-src ext/posix/posix.c; #6489). */
final class posix_getgrnam extends Internal
{
    public function __construct()
    {
        parent::__construct('posix_getgrnam');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('posix_getgrnam() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        InternalStrictArg::requireString($frame, 0, 'posix_getgrnam', 'name');
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'posix_getgrnam', 0, 'name');
        $entry = VmPosix::getgrnam($name);
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
                'posix_getgrnam() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        throw new \Error('posix_getgrnam() is not implemented for JIT in this compiler build (issue #6489)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gethostname() — local hostname via VmHost / GethostnameJitHelper (#3465/#5022/#21166). */
final class gethostname extends Internal
{
    public function __construct()
    {
        parent::__construct('gethostname');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('gethostname() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $host = VmHost::gethostname();
        if (false === $host) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($host);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'gethostname() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGethostname::invoke($context);
    }
}

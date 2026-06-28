<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * proc_terminate() — signal subprocess (php-src ext/standard/proc_open.c; #3740).
 *
 * VM: {@see VmProcess::procTerminate()}; JIT/AOT: __compiler_proc_terminate (#3740).
 */
final class proc_terminate extends Internal
{
    public function __construct()
    {
        parent::__construct('proc_terminate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'proc_terminate() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                'proc_terminate() expects at most 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $procVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = proc_close::requireProcessHandle($procVar, 'proc_terminate');
        $signal = 15;
        if (2 === $argc) {
            $signal = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'proc_terminate',
                2,
                'signal'
            );
        }
        $frame->returnVar->bool(VmProcess::procTerminate($handle, $signal));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'proc_terminate() expects at least 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if ($argc > 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'proc_terminate() expects at most 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitProcTerminate::invoke($context, $args[0], $args[1] ?? null);
    }
}

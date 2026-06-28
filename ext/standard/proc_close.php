<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ResourceSupport;
use PHPLLVM\Value;

/**
 * proc_close() — close proc_open() process (php-src ext/standard/proc_open.c; #3131, #6904).
 *
 * VM: {@see VmProcess::procClose()}; JIT/AOT: __compiler_proc_close.
 */
final class proc_close extends Internal
{
    public function __construct()
    {
        parent::__construct('proc_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'proc_close() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $procVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = self::requireProcessHandle($procVar, 'proc_close');
        $frame->returnVar->int(VmProcess::procClose($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'proc_close() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitProcClose::invoke($context, $args[0]);
    }

    public static function requireProcessHandle(\PHPCompiler\VM\Variable $v, string $functionName): int
    {
        $v = $v->resolveIndirect();
        if (!$v->isProcessResource()) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($process) must be of type resource, %s given',
                $functionName,
                VmStreamArg::debugTypeName($v)
            ));
        }
        $handle = ResourceSupport::resolveHandle($v);
        if (null === $handle || !VmProcess::isValidHandle($handle)) {
            throw new \TypeError(\sprintf(
                '%s(): supplied resource is not a valid process resource',
                $functionName
            ));
        }

        return $handle;
    }
}

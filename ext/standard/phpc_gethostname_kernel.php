<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc gethostname(2) kernel for GethostnameJitHelper (#21166).
 *
 * Avoids compiling {@see VmHostPure} when the helper TU is NestedJIT'd into
 * user-script AOT (same shape as {@see phpc_chdir_kernel} / {@see phpc_getenv_kernel}).
 */
final class phpc_gethostname_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_gethostname_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \LogicException('phpc_gethostname_kernel() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $host = @\gethostname();
        if (false === $host || '' === $host) {
            $frame->returnVar->string('');
        } else {
            $frame->returnVar->string((string) $host);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \LogicException('phpc_gethostname_kernel() expects exactly 0 arguments');
        }

        return JitGethostnameKernel::invoke($context);
    }
}

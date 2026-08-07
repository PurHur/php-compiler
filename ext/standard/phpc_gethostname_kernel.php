<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal hostname NestedJIT kernel for GethostnameJitHelper (#21166, #28544).
 *
 * VM execute uses {@see VmHostPure}; JIT NestedJIT leaf emits /proc+/etc open/read
 * ({@see JitGethostnameKernel}) — avoids compiling Pure into the helper TU and drops
 * libc gethostname(2) (peer {@see phpc_random_bytes_kernel} / #21186).
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
        $host = VmHostPure::gethostname();
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

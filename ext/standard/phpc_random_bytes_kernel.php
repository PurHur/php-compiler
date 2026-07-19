<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc /dev/urandom kernel for RandomBytesJitHelper (#21186).
 *
 * VM: {@see VmRandomPure}; JIT/AOT NestedJIT leaf: {@see JitRandomBytesKernel}.
 * php-src: ext/standard/random.c — php_random_bytes()
 */
final class phpc_random_bytes_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_random_bytes_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_random_bytes_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $length = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'phpc_random_bytes_kernel',
            0,
            'length'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmRandomPure::randomBytes($length));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_random_bytes_kernel() expects exactly 1 argument');
        }

        return JitRandomBytesKernel::invoke(
            $context,
            JitLongArg::lower($context, $args[0], 'phpc_random_bytes_kernel() length')
        );
    }
}

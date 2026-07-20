<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libc write(1, …) kernel for ObOutputJitHelper::writeStdout (#21469).
 *
 * Avoids echo→ob_append recursion when the helper TU is NestedJIT'd into standalone AOT.
 */
final class phpc_ob_write_stdout_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_ob_write_stdout_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \LogicException('phpc_ob_write_stdout_kernel() expects exactly 1 argument, '.$argc.' given');
        }
        $chunk = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'phpc_ob_write_stdout_kernel',
            0,
            'chunk'
        );
        if ('' !== $chunk) {
            if (\defined('STDOUT') && \is_resource(\STDOUT)) {
                @\fwrite(\STDOUT, $chunk);
            } else {
                echo $chunk;
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_ob_write_stdout_kernel() expects exactly 1 argument');
        }
        $chunk = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'phpc_ob_write_stdout_kernel',
            0,
            'chunk'
        );
        JitObWriteStdoutKernel::invoke($context, $chunk);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}

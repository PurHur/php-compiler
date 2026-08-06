<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * lzf_optimized_for() — PECL lzf.stub.php / lzf.c (#28063).
 *
 * Bundled / pure-PHP {@see VmLzfCore} path matches PHP_LZF_ULTRA_FAST (1).
 * System liblzf builds return false; this compiler never links system liblzf.
 */
final class lzf_optimized_for extends Internal
{
    public function __construct()
    {
        parent::__construct('lzf_optimized_for');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'lzf_optimized_for() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmLzf::optimizedFor());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'lzf_optimized_for() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitLzf::optimizedFor($context);
    }
}

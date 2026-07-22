<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * bzflush() — php-src @implementation-alias fflush (#22344).
 *
 * For bz2 stream placeholders, flush always succeeds while the handle is open.
 */
final class bzflush extends Internal
{
    public function __construct()
    {
        parent::__construct('bzflush');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('bzflush() expects exactly 1 argument in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'bzflush'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmBz2Error::flush($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('bzflush() expects exactly 1 argument in this compiler build');
        }

        return JitBzflush::invoke(
            $context,
            JitLongArg::lower($context, $args[0], 'bzflush() stream')
        );
    }
}

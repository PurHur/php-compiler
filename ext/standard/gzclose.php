<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzclose() — close gzip stream (ext/zlib/zlib.c parity, #6168). */
final class gzclose extends Internal
{
    public function __construct()
    {
        parent::__construct('gzclose');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('gzclose() requires exactly one argument in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), 'gzclose');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmGzStream::gzclose($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('gzclose() requires exactly one argument in this compiler build');
        }

        return JitGzclose::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'gzclose() stream'),
                $context->getTypeFromString('int64')
            )
        );
    }
}

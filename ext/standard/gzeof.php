<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzeof() — EOF probe on gzip stream (ext/zlib/zlib.c, #14596). */
final class gzeof extends Internal
{
    public function __construct()
    {
        parent::__construct('gzeof');
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException($fn.'() expects exactly one argument in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmGzStream::gzeof($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (1 !== \count($args)) {
            throw new \LogicException($fn.'() expects exactly one argument in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() stream'),
            $i64
        );

        return JitGzeof::invoke($context, $handle);
    }
}

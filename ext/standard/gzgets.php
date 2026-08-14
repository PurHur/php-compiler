<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzgets() — line-oriented gzip stream read (ext/zlib/zlib.c parity, #6290). */
final class gzgets extends Internal
{
    public function __construct()
    {
        parent::__construct('gzgets');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30830).
        $fn = $this->getName();
        $this->requireArgCountRange($frame, $fn, 1, 2);
        $argc = \count($frame->calledArgs);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $length = 8192;
        if (2 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                $fn,
                2,
                'length'
            );
        }
        $line = VmGzStream::gzgets($handle, $length);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireArgCountRangeJit($context, $args, $fn, 1, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() stream'),
            $i64
        );
        $length = $i64->constInt(8192, false);
        if (2 === $argc) {
            $length = JitIntdiv::lowerIntBuiltinArg($context, $args[1], $fn, 2, 'length');
        }

        return JitGzgets::invoke($context, $handle, $length);
    }
}

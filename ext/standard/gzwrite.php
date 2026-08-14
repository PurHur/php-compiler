<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzwrite() — write to gzip stream (ext/zlib/zlib.c parity, #6168). */
final class gzwrite extends Internal
{
    public function __construct(string $name = 'gzwrite')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30830).
        $fn = $this->getName();
        $this->requireArgCountRange($frame, $fn, 2, 3);
        $argc = \count($frame->calledArgs);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $fn, 1, 'string');
        $length = null;
        if (3 === $argc) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                $fn,
                3,
                'length'
            );
        }
        $written = VmGzStream::gzwrite($handle, $data, $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireArgCountRangeJit($context, $args, $fn, 2, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() stream'),
            $i64
        );
        $length = $i64->constInt(-1, true);
        if (3 === $argc) {
            $length = JitIntdiv::lowerIntBuiltinArg($context, $args[2], $fn, 3, 'length');
        }

        return JitGzwrite::invoke(
            $context,
            $handle,
            JitStringBuiltinArg::lower($context, $args[1], $fn, 1, 'string'),
            $length
        );
    }
}

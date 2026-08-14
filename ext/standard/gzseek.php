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

/** gzseek() — seek within gzip stream (ext/zlib/zlib.c, #14585). */
final class gzseek extends Internal
{
    public function __construct()
    {
        parent::__construct('gzseek');
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
        $offset = VmMath::parseIntBuiltinArgForFrame($frame, 1, $fn, 2, 'offset');
        $whence = \SEEK_SET;
        if (3 === $argc) {
            $whence = VmMath::parseIntBuiltinArgForFrame($frame, 2, $fn, 3, 'whence');
        }
        $frame->returnVar->int(VmGzStream::gzseek($handle, $offset, $whence));
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
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], $fn, 2, 'offset');
        if (3 === $argc) {
            $whence = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], $fn.'() whence'),
                $i64
            );
        } else {
            $whence = $i64->constInt(\SEEK_SET, false);
        }

        return JitGzseek::invoke($context, $handle, $offset, $whence);
    }
}

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

/** gzread() — read from gzip stream (ext/zlib/zlib.c parity, #6168). */
final class gzread extends Internal
{
    public function __construct()
    {
        parent::__construct('gzread');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30830).
        $fn = $this->getName();
        $this->requireExactArgCount($frame, $fn, 2);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $length = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            $fn,
            2,
            'length'
        );
        $data = VmGzStream::gzread($handle, $length);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireExactJitArgCount($context, $args, $fn, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], $fn.'() stream'),
            $i64
        );
        $length = JitIntdiv::lowerIntBuiltinArg($context, $args[1], $fn, 2, 'length');

        return JitGzread::invoke($context, $handle, $length);
    }
}

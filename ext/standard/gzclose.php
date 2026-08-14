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

/** gzclose() — close gzip stream (ext/zlib/zlib.c parity, #6168). */
final class gzclose extends Internal
{
    public function __construct()
    {
        parent::__construct('gzclose');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30830).
        $fn = $this->getName();
        $this->requireExactArgCount($frame, $fn, 1);
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), 'gzclose');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmGzStream::gzclose($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireExactJitArgCount($context, $args, $fn, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
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

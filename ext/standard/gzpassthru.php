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

/** gzpassthru() — output remaining gzip stream bytes to stdout (ext/zlib/zlib.c parity, #4657). */
final class gzpassthru extends Internal
{
    public function __construct()
    {
        parent::__construct('gzpassthru');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30830).
        $fn = $this->getName();
        $this->requireExactArgCount($frame, $fn, 1);
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0]->resolveIndirect(), 'gzpassthru');
        $result = VmGzStream::gzpassthru($handle);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireExactJitArgCount($context, $args, $fn, 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGzpassthru::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'gzpassthru() stream'),
                $context->getTypeFromString('int64')
            )
        );
    }
}

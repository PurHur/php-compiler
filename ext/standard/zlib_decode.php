<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** zlib_decode() — auto-detect zlib/gzip/deflate decompress (ext/zlib/zlib.c, issue #6288). */
final class zlib_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('zlib_decode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'zlib_decode', 1, 2);
        $argc = \count($frame->calledArgs);
        $data = VmZlibArg::resolveDataString($frame, 'zlib_decode');
        $maxLength = 0;
        if (2 === $argc) {
            $maxLength = VmZlibArg::coerceInt($frame, 1, 'zlib_decode', 2, 'max_length');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::zlib_decode($data, $maxLength);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'zlib_decode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'zlib_decode', 1, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $maxLength = $context->getTypeFromString('int64')->constInt(0, false);
        if (2 === $argc) {
            $maxLength = JitStrictIntArg::lower($context, $args[1], 'zlib_decode', 2, 'max_length');
        }

        return JitZlib::zlibDecode(
            $context,
            VmZlibArg::jitDataString($context, $args[0], 'zlib_decode'),
            $maxLength
        );
    }
}

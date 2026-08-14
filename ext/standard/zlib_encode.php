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

/** zlib_encode() — one-shot zlib/gzip/deflate compress (ext/zlib/zlib.c, issue #6288). */
final class zlib_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('zlib_encode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'zlib_encode', 2, 3);
        $argc = \count($frame->calledArgs);
        $data = VmZlibArg::resolveDataString($frame, 'zlib_encode');
        $encoding = VmZlibArg::coerceInt($frame, 1, 'zlib_encode', 2, 'encoding');
        self::assertValidEncoding($encoding);
        $level = -1;
        if (3 === $argc) {
            $level = VmZlibArg::coerceLevel($frame, 2, 'zlib_encode', 3, 'level');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::zlib_encode($data, $encoding, $level);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'zlib_encode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'zlib_encode', 2, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        if (3 === $argc) {
            $level = JitStrictIntArg::lowerLevel($context, $args[2], 'zlib_encode', 3, 'level');
        }

        return JitZlib::zlibEncode(
            $context,
            VmZlibArg::jitDataString($context, $args[0], 'zlib_encode'),
            JitStrictIntArg::lower($context, $args[1], 'zlib_encode', 2, 'encoding'),
            $level
        );
    }

    private static function assertValidEncoding(int $encoding): void
    {
        if (
            \ZLIB_ENCODING_RAW === $encoding
            || \ZLIB_ENCODING_DEFLATE === $encoding
            || \ZLIB_ENCODING_GZIP === $encoding
            || 65534 === $encoding
            || 65535 === $encoding
            || 16 === $encoding
        ) {
            return;
        }

        throw new \ValueError('zlib_encode(): Argument #2 ($encoding) must be one of ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE');
    }
}

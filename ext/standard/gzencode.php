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

/** gzencode() — gzip-encoded string (ext/zlib/zlib.c parity, issue #3194). */
final class gzencode extends Internal
{
    public function __construct()
    {
        parent::__construct('gzencode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'gzencode', 1, 3);
        $argc = \count($frame->calledArgs);
        $data = VmZlibArg::resolveDataString($frame, 'gzencode');
        $level = -1;
        $encoding = \ZLIB_ENCODING_GZIP;
        // Named encoding: without level leaves calledArgs[1] unset (#25012).
        if (isset($frame->calledArgs[1])) {
            $level = VmZlibArg::coerceLevel($frame, 1, 'gzencode');
        }
        if (isset($frame->calledArgs[2])) {
            $encoding = VmZlibArg::coerceInt($frame, 2, 'gzencode', 3, 'encoding');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzencode($data, $level, $encoding);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzencode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'gzencode', 1, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        $encoding = $i64->constInt(\ZLIB_ENCODING_GZIP, false);
        if ($argc >= 2) {
            $level = JitStrictIntArg::lowerLevel($context, $args[1], 'gzencode');
        }
        if (3 === $argc) {
            $encoding = JitStrictIntArg::lower($context, $args[2], 'gzencode', 3, 'encoding');
        }

        return JitZlib::encode(
            $context,
            VmZlibArg::jitDataString($context, $args[0], 'gzencode'),
            $level,
            $encoding
        );
    }
}

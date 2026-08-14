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

/** gzdeflate() — raw deflate (ext/zlib/zlib.c parity, issue #3194). */
final class gzdeflate extends Internal
{
    public function __construct()
    {
        parent::__construct('gzdeflate');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'gzdeflate', 1, 3);
        $argc = \count($frame->calledArgs);
        $data = VmZlibArg::resolveDataString($frame, 'gzdeflate');
        $level = -1;
        $encoding = \ZLIB_ENCODING_RAW;
        // Named encoding without level — sparse calledArgs (#25012 sibling).
        if (isset($frame->calledArgs[1])) {
            $level = VmZlibArg::coerceLevel($frame, 1, 'gzdeflate');
        }
        if (isset($frame->calledArgs[2])) {
            $encoding = VmZlibArg::coerceInt($frame, 2, 'gzdeflate', 3, 'encoding');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzdeflate($data, $level, $encoding);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzdeflate(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'gzdeflate', 1, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        $encoding = $i64->constInt(\ZLIB_ENCODING_RAW, false);
        if ($argc >= 2) {
            $level = JitStrictIntArg::lowerLevel($context, $args[1], 'gzdeflate');
        }
        if (3 === $argc) {
            $encoding = JitStrictIntArg::lower($context, $args[2], 'gzdeflate', 3, 'encoding');
        }

        return JitZlib::deflate(
            $context,
            VmZlibArg::jitDataString($context, $args[0], 'gzdeflate'),
            $level,
            $encoding
        );
    }
}

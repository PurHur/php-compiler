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

/** gzinflate() — inflate zlib-compressed data (ext/zlib/zlib.c parity, issue #3194). */
final class gzinflate extends Internal
{
    public function __construct()
    {
        parent::__construct('gzinflate');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/zlib/zlib.c — ArgumentCountError (#30829).
        $this->requireArgCountRange($frame, 'gzinflate', 1, 2);
        $argc = \count($frame->calledArgs);
        $data = VmZlibArg::resolveDataString($frame, 'gzinflate');
        $maxLength = 0;
        if (2 === $argc) {
            $maxLength = VmZlibArg::coerceInt($frame, 1, 'gzinflate', 2, 'max_length');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzinflate($data, $maxLength);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzinflate(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'gzinflate', 1, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $maxLength = $context->getTypeFromString('int64')->constInt(0, false);
        if (2 === $argc) {
            $maxLength = JitStrictIntArg::lower($context, $args[1], 'gzinflate', 2, 'max_length');
        }

        return JitZlib::inflate(
            $context,
            VmZlibArg::jitDataString($context, $args[0], 'gzinflate'),
            $maxLength
        );
    }
}

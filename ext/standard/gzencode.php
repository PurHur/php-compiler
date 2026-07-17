<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
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
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('gzencode() expects one to three arguments in this compiler build');
        }
        $data = VmZlibArg::resolveDataString($frame, 'gzencode');
        $level = -1;
        $encoding = \ZLIB_ENCODING_GZIP;
        if ($argc >= 2) {
            $level = VmZlibArg::coerceLevel($frame, 1, 'gzencode');
        }
        if (3 === $argc) {
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
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('gzencode() expects one to three arguments in this compiler build');
        }
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

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** zstd_decompress() — pure PHP via VmZstdCore (php-src ext/zstd/zstd.c; #6382, #6387, #8869). */
final class zstd_decompress extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_decompress');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('zstd_decompress() expects exactly one argument in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_decompress', 0, 'data');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdNative::decompress($data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('zstd_decompress() expects exactly one argument in this compiler build');
        }

        return JitZstd::decompress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'zstd_decompress', 0, 'data')
        );
    }
}

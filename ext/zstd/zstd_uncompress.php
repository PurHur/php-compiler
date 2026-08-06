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

/** zstd_uncompress() — alias of zstd_decompress() (php-src ext/zstd/zstd.c; #6382). */
final class zstd_uncompress extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_uncompress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('zstd_uncompress() expects one or two arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_uncompress', 0, 'data');
        if (2 === $argc) {
            $dictVar = $frame->calledArgs[1]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_NULL !== $dictVar->type) {
                if (null === $frame->returnVar) {
                    return;
                }
                $frame->returnVar->bool(false);

                return;
            }
        }
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
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('zstd_uncompress() expects one or two arguments in this compiler build');
        }
        if (2 === \count($args)) {
            throw new \LogicException('zstd_uncompress() dictionary argument is VM-only in this compiler build (#27882)');
        }

        return JitZstd::decompress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'zstd_uncompress', 0, 'data')
        );
    }
}

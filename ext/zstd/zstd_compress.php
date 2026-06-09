<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** zstd_compress() — libzstd via FFI (php-src ext/zstd/zstd.c; #6382, #6387). */
final class zstd_compress extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_compress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('zstd_compress() expects one or two arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_compress', 0, 'data');
        $level = 3;
        if (2 === $argc) {
            $levelVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \LogicException('zstd_compress() level must be an integer in this compiler build');
            }
            $level = $levelVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdNative::compress($data, $level);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('zstd_compress() is not implemented for JIT in this compiler build (issue #6387)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** zstd_uncompress_add() — streaming decompress chunk (kjdev/php-ext-zstd; #27882). */
final class zstd_uncompress_add extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_uncompress_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('zstd_uncompress_add() expects exactly 2 arguments, '.$argc.' given');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'zstd_uncompress_add', 1, 'data');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdContext::uncompressAdd($frame->calledArgs[0], $data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('zstd_uncompress_add() is VM-only in this compiler build (#27882)');
    }
}

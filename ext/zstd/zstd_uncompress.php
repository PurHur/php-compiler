<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('zstd_uncompress() expects exactly one argument in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_uncompress', 0, 'data');
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
        throw new \Error('zstd_uncompress() is not implemented for JIT in this compiler build (issue #6387)');
    }
}

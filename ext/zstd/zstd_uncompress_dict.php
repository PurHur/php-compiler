<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * zstd_uncompress_dict() — deprecated PECL alias (kjdev/php-ext-zstd; #27882).
 *
 * Dictionary frames are not implemented yet — returns false when $dict is non-empty.
 */
final class zstd_uncompress_dict extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_uncompress_dict');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('zstd_uncompress_dict() expects exactly 2 arguments, '.$argc.' given');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_uncompress_dict', 0, 'data');
        $dict = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'zstd_uncompress_dict', 1, 'dict');
        if (null === $frame->returnVar) {
            return;
        }
        if ('' !== $dict) {
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmZstdNative::decompress($data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('zstd_uncompress_dict() is VM-only in this compiler build (#27882)');
    }
}

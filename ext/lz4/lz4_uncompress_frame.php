<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** lz4_uncompress_frame() — LZ4F frame decompress (kjdev/php-ext-lz4; #27883). */
final class lz4_uncompress_frame extends Internal
{
    public function __construct()
    {
        parent::__construct('lz4_uncompress_frame');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'lz4_uncompress_frame() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'lz4_uncompress_frame', 0, 'data');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLz4Native::uncompressFrame($data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('lz4_uncompress_frame() is VM-only in this compiler build (#27883)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lzf;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** lzf_compress() — liblzf via pure PHP (php-src ext/lzf/lzf.c; #6384). */
final class lzf_compress extends Internal
{
    public function __construct()
    {
        parent::__construct('lzf_compress');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('lzf_compress() expects exactly one argument in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'lzf_compress', 0, 'data');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLzf::compress($data);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('lzf_compress() is not implemented for JIT in this compiler build (issue #6384)');
    }
}

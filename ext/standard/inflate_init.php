<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** inflate_init() — incremental inflate context (ext/zlib/zlib.c, issue #4656). */
final class inflate_init extends ZlibIncrementalFunction
{
    public function __construct()
    {
        parent::__construct('inflate_init');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('inflate_init() expects exactly one argument in this compiler build');
        }
        $encoding = VmZlibArg::requireInt($frame->calledArgs[0], 'inflate_init', 1, 'encoding');
        $ctx = VmZlibContext::inflateInit(VmReflection::requireContext($frame), $encoding);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ctx): void {
            $ret->copyFrom($ctx);
        });
    }
}

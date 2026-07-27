<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** inflate_init() — incremental inflate context (ext/zlib/zlib.c, issue #4656 / #23642). */
final class inflate_init extends ZlibIncrementalFunction
{
    public function __construct()
    {
        parent::__construct('inflate_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $this->requireArgCountBetween($argc, 1, 2);
        $encoding = VmZlibArg::resolveEncodingInt($frame, 0, 'inflate_init', 1, 'encoding');
        $options = [];
        if (2 === $argc) {
            $options = VmZlibContext::parseOptionsVariable($frame->calledArgs[1], 'inflate_init');
        }
        $ctx = VmZlibContext::inflateInit(VmReflection::requireContext($frame), $encoding, $options);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ctx): void {
            $ret->copyFrom($ctx);
        });
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;

/** deflate_init() — incremental deflate context (ext/zlib/zlib.c, issue #4656). */
final class deflate_init extends ZlibIncrementalFunction
{
    public function __construct()
    {
        parent::__construct('deflate_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('deflate_init() expects one or two arguments in this compiler build');
        }
        $encoding = VmZlibArg::parseEncodingZParamForFrame($frame, 0, 'deflate_init', 1, 'encoding');
        $options = [];
        if (2 === $argc) {
            $options = VmZlibContext::parseOptionsVariable($frame->calledArgs[1], 'deflate_init');
        }
        $ctx = VmZlibContext::deflateInit(VmReflection::requireContext($frame), $encoding, $options);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ctx): void {
            $ret->copyFrom($ctx);
        });
    }
}

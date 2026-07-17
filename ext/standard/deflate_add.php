<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;

/** deflate_add() — append to incremental deflate context (ext/zlib/zlib.c, issue #4656). */
final class deflate_add extends ZlibIncrementalFunction
{
    public function __construct()
    {
        parent::__construct('deflate_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $this->requireArgCountBetween($argc, 2, 3);
        $ctx = VmZlibContext::requireZlibContext(
            $frame->calledArgs[0],
            'deflate_add',
            1,
            VmZlibContext::DEFLATE_CLASS_LC,
            'DeflateContext'
        );
        $data = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'deflate_add', 2, 'data');
        $flush = \ZLIB_NO_FLUSH;
        if (3 === $argc) {
            $flush = VmZlibArg::requireInt($frame->calledArgs[2], 'deflate_add', 3, 'flush');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlibContext::deflateAdd($ctx, $data, $flush);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'deflate_add(): data error');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

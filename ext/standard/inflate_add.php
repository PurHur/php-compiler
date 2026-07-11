<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;

/** inflate_add() — append to incremental inflate context (ext/zlib/zlib.c, issue #4656). */
final class inflate_add extends ZlibIncrementalFunction
{
    public function __construct()
    {
        parent::__construct('inflate_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('inflate_add() expects two or three arguments in this compiler build');
        }
        $ctx = VmZlibContext::requireZlibContext(
            $frame->calledArgs[0],
            'inflate_add',
            1,
            VmZlibContext::INFLATE_CLASS_LC,
            'InflateContext'
        );
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'inflate_add', 2, 'data');
        $flush = \ZLIB_NO_FLUSH;
        if (3 === $argc) {
            $flush = VmZlibArg::requireInt($frame->calledArgs[2], 'inflate_add', 3, 'flush');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlibContext::inflateAdd($ctx, $data, $flush);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'inflate_add(): data error');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

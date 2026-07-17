<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;

/**
 * inflate_get_read_len() — bytes consumed by inflate (ext/zlib/zlib.c, #20008).
 *
 * php-src: ext/zlib/zlib.c — PHP_FUNCTION(inflate_get_read_len)
 */
final class inflate_get_read_len extends ZlibIncrementalFunction
{
    public function __construct()
    {
        parent::__construct('inflate_get_read_len');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountBetween(\count($frame->calledArgs), 1, 1);
        $ctx = VmZlibContext::requireZlibContext(
            $frame->calledArgs[0],
            'inflate_get_read_len',
            1,
            VmZlibContext::INFLATE_CLASS_LC,
            'InflateContext'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmZlibContext::inflateGetReadLen($ctx));
    }
}

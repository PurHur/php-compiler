<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** mailparse_stream_encode() — encode between streams (PECL mailparse; #22230). */
final class mailparse_stream_encode extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_stream_encode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_stream_encode() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $src = VmStreamArg::requireStreamHandle($frame->calledArgs[0], 'mailparse_stream_encode', 1);
        $dest = VmStreamArg::requireStreamHandle($frame->calledArgs[1], 'mailparse_stream_encode', 2);
        $encoding = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'mailparse_stream_encode', 2, 'encoding');
        $ok = VmMailparse::streamEncode($src, $dest, $encoding);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

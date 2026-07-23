<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;

/** mailparse_determine_best_xfer_encoding() — scan stream (PECL mailparse; #22230). */
final class mailparse_determine_best_xfer_encoding extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_determine_best_xfer_encoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_determine_best_xfer_encoding() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0],
            'mailparse_determine_best_xfer_encoding',
            1
        );
        $enc = VmMailparse::determineBestXferEncoding($handle);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $enc) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($enc);
    }
}

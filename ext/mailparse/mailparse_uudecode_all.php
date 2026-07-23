<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\Frame;

/** mailparse_uudecode_all() — extract uuencoded parts from stream (PECL mailparse; #22230). */
final class mailparse_uudecode_all extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_uudecode_all');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_uudecode_all() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $handle = VmStreamArg::requireStreamHandle($frame->calledArgs[0], 'mailparse_uudecode_all', 1);
        $parts = VmMailparse::uudecodeAll($handle);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $parts) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmMailparse::uudecodeVariable($parts));
    }
}

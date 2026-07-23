<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\Frame;

/** mailparse_msg_get_structure() — MIME section id list (PECL mailparse; #22230). */
final class mailparse_msg_get_structure extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_get_structure');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_get_structure() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $msg = VmMailparse::requireMsgArg($frame->calledArgs[0], 'mailparse_msg_get_structure', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmMailparse::structureVariable($msg));
    }
}

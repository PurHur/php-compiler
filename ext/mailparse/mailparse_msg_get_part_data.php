<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\Frame;

/** mailparse_msg_get_part_data() — associative info about a MIME part (PECL mailparse; #6383). */
final class mailparse_msg_get_part_data extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_get_part_data');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_get_part_data() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $msg = VmMailparse::requireMsgArg($frame->calledArgs[0], 'mailparse_msg_get_part_data', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmMailparse::partDataVariable($msg));
    }
}

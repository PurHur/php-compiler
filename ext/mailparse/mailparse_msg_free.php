<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\Frame;

/** mailparse_msg_free() — free MIME resource (PECL mailparse; #6383). */
final class mailparse_msg_free extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_free');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_free() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $msg = VmMailparse::requireMsgArg($frame->calledArgs[0], 'mailparse_msg_free', 0);
        $ok = VmMailparse::free($msg);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

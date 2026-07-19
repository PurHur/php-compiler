<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** mailparse_msg_parse() — incrementally parse MIME data (PECL mailparse; #6383). */
final class mailparse_msg_parse extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_parse');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_parse() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $msg = VmMailparse::requireMsgArg($frame->calledArgs[0], 'mailparse_msg_parse', 0);
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'mailparse_msg_parse', 1, 'data');
        $ok = VmMailparse::parse($msg, $data);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

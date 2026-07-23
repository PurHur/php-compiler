<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** mailparse_msg_parse_file() — parse a file into a MIME resource (PECL mailparse; #22230). */
final class mailparse_msg_parse_file extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_parse_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_parse_file() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'mailparse_msg_parse_file', 0, 'filename');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('mailparse_msg_parse_file() requires a VM context');
        }
        $msg = VmMailparse::parseFile($ctx, $filename);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $msg) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($msg);
    }
}

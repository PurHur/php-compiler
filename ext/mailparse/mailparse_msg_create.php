<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\Frame;

/** mailparse_msg_create() — create MIME mail resource (PECL mailparse; #6383). */
final class mailparse_msg_create extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_create');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_msg_create() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('mailparse_msg_create() requires a VM context');
        }
        $frame->returnVar->copyFrom(VmMailparse::create($ctx));
    }
}

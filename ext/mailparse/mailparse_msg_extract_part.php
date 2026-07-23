<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\Frame;

/** mailparse_msg_extract_part() — decode body from msg string (PECL mailparse; #22230). */
final class mailparse_msg_extract_part extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_extract_part');
    }

    public function execute(Frame $frame): void
    {
        MailparseExtract::execute(
            $frame,
            'mailparse_msg_extract_part',
            VmMailparse::extractDecodeBodyFlags(),
            0
        );
    }
}

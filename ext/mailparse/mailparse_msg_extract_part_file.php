<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\Frame;

/** mailparse_msg_extract_part_file() — decode body from file (PECL mailparse; #22230). */
final class mailparse_msg_extract_part_file extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_msg_extract_part_file');
    }

    public function execute(Frame $frame): void
    {
        MailparseExtract::execute(
            $frame,
            'mailparse_msg_extract_part_file',
            VmMailparse::extractDecodeBodyFlags(),
            1
        );
    }
}

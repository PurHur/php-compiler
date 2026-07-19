<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mailparse;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/** mailparse_rfc822_parse_addresses() — parse RFC 822 address list (PECL mailparse; #6383). */
final class mailparse_rfc822_parse_addresses extends MailparseFunction
{
    public function __construct()
    {
        parent::__construct('mailparse_rfc822_parse_addresses');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'mailparse_rfc822_parse_addresses() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $addresses = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'mailparse_rfc822_parse_addresses',
            0,
            'addresses'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmMailparse::addressesVariable($addresses));
    }
}

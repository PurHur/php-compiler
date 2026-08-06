<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_mail_move() — move messages to another mailbox (php-src ext/imap/php_imap.c; #27780). */
final class imap_mail_move extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_mail_move');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_mail_move', 3, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_mail_move');
        $sequence = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_mail_move', 1, 'message_nums');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_mail_move', 2, 'mailbox');
        $flags = 0;
        if ($argc >= 4) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_mail_move', 4, 'flags');
            if (0 !== ($flags & ~VmImapCore::CP_UID)) {
                throw new \ValueError('imap_mail_move(): Argument #4 ($flags) must be CP_UID or 0');
            }
        }
        $frame->returnVar->bool(VmImapCore::moveMessages($connection, $sequence, $mailbox, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_mail_move() is not implemented for JIT in this compiler build (issue #27780)');
    }
}

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

/** imap_reopen() — rebind connection to a mailbox (php-src ext/imap/php_imap.c; #27815). */
final class imap_reopen extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_reopen');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_reopen', 2, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_reopen');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_reopen', 1, 'mailbox');
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_reopen', 3, 'flags');
        }
        $retries = 0;
        if ($argc >= 4) {
            $retries = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_reopen', 4, 'retries');
        }
        $frame->returnVar->bool(VmImapCore::reopen($connection, $mailbox, $flags, $retries));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_reopen() is not implemented for JIT in this compiler build (issue #27815)');
    }
}

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

/** imap_set_quota() — set STORAGE limit (KB) for a quota root (php-src ext/imap/php_imap.c; #27816). */
final class imap_set_quota extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_set_quota');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_set_quota', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_set_quota');
        $quotaRoot = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_set_quota', 1, 'quota_root');
        $mailboxSize = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_set_quota', 3, 'mailbox_size');
        $frame->returnVar->bool(VmImapCore::setQuota($connection, $quotaRoot, $mailboxSize));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_set_quota() is not implemented for JIT in this compiler build (issue #27816)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_deletemailbox() — delete local mailbox file (php-src ext/imap/php_imap.c; #27799). */
final class imap_deletemailbox extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_deletemailbox');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_deletemailbox', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_deletemailbox');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_deletemailbox', 1, 'mailbox');
        $frame->returnVar->bool(VmImapCore::deleteMailbox($connection, $mailbox));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_deletemailbox() is not implemented for JIT in this compiler build (issue #27799)');
    }
}

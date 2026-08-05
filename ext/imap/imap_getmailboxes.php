<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_getmailboxes() — detailed mailbox list (php-src ext/imap/php_imap.c; #27799). */
final class imap_getmailboxes extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_getmailboxes');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_getmailboxes', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_getmailboxes');
        $reference = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_getmailboxes', 1, 'reference');
        $pattern = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_getmailboxes', 2, 'pattern');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_getmailboxes() requires a VM context');
        }
        $result = VmImapCore::getMailboxes($connection, $reference, $pattern, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_getmailboxes() is not implemented for JIT in this compiler build (issue #27799)');
    }
}

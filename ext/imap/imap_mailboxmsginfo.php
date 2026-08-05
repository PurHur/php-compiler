<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_mailboxmsginfo() — mailbox summary object (php-src ext/imap/php_imap.c; #27800). */
final class imap_mailboxmsginfo extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_mailboxmsginfo');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_mailboxmsginfo', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_mailboxmsginfo');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_mailboxmsginfo() requires a VM context');
        }
        $result = VmImapCore::mailboxMsgInfo($connection, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_mailboxmsginfo() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

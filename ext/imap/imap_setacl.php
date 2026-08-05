<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_setacl() — set mailbox ACL rights (php-src ext/imap/php_imap.c; #27800). */
final class imap_setacl extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_setacl');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_setacl', 4);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_setacl');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_setacl', 1, 'mailbox');
        $userId = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_setacl', 2, 'user_id');
        $rights = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'imap_setacl', 3, 'rights');
        $frame->returnVar->bool(VmImapCore::setAcl($connection, $mailbox, $userId, $rights));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_setacl() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

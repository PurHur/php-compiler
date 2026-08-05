<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_getacl() — mailbox ACL map (php-src ext/imap/php_imap.c; #27800). */
final class imap_getacl extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_getacl');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_getacl', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_getacl');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_getacl', 1, 'mailbox');
        $acl = VmImapCore::getAcl($connection, $mailbox);
        if (false === $acl) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::stringMapToHashTable($acl));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_getacl() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_getsubscribed() — subscribed mailboxes as getmailboxes()-shaped objects (php-src; #27817). */
final class imap_getsubscribed extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_getsubscribed');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_getsubscribed', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_getsubscribed');
        $reference = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_getsubscribed', 1, 'reference');
        $pattern = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_getsubscribed', 2, 'pattern');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_getsubscribed() requires a VM context');
        }
        $result = VmImapCore::getSubscribed($connection, $reference, $pattern, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_getsubscribed() is not implemented for JIT in this compiler build (issue #27817)');
    }
}

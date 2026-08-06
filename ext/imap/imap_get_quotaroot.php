<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_get_quotaroot() — quota for the root owning a mailbox (php-src ext/imap/php_imap.c; #27816). */
final class imap_get_quotaroot extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_get_quotaroot');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_get_quotaroot', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_get_quotaroot');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_get_quotaroot', 1, 'mailbox');
        $result = VmImapCore::getQuotaRoot($connection, $mailbox);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_get_quotaroot() is not implemented for JIT in this compiler build (issue #27816)');
    }
}

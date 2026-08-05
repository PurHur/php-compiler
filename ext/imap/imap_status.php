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

/** imap_status() — mailbox status object (php-src ext/imap/php_imap.c; #27783). */
final class imap_status extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_status');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_status', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_status');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_status', 1, 'mailbox');
        $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_status', 3, 'flags');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_status() requires a VM context');
        }
        $result = VmImapCore::status($connection, $mailbox, $flags, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_status() is not implemented for JIT in this compiler build (issue #27783)');
    }
}

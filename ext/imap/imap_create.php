<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_create() — alias of imap_createmailbox (php-src ext/imap/php_imap.stub.php; #27820). */
final class imap_create extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_create');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_create', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_create');
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_create', 1, 'mailbox');
        $frame->returnVar->bool(VmImapCore::createMailbox($connection, $mailbox));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_create() is not implemented for JIT in this compiler build (issue #27820)');
    }
}

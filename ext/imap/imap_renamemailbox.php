<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_renamemailbox() — rename local mailbox file (php-src ext/imap/php_imap.c; #27799). */
final class imap_renamemailbox extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_renamemailbox');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_renamemailbox', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_renamemailbox');
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_renamemailbox', 1, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_renamemailbox', 2, 'to');
        $frame->returnVar->bool(VmImapCore::renameMailbox($connection, $from, $to));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_renamemailbox() is not implemented for JIT in this compiler build (issue #27799)');
    }
}

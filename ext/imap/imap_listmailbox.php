<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_listmailbox() — alias of imap_list (php-src ext/imap/php_imap.stub.php; #27820). */
final class imap_listmailbox extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_listmailbox');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_listmailbox', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_listmailbox');
        $reference = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_listmailbox', 1, 'reference');
        $pattern = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_listmailbox', 2, 'pattern');
        $names = VmImapCore::listMailboxes($connection, $reference, $pattern);
        if (false === $names) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::stringListToHashTable($names));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_listmailbox() is not implemented for JIT in this compiler build (issue #27820)');
    }
}

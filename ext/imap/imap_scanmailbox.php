<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_scanmailbox() — alias of imap_listscan (php-src ext/imap/php_imap.stub.php; #27817). */
final class imap_scanmailbox extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_scanmailbox');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_scanmailbox', 4);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_scanmailbox');
        $reference = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_scanmailbox', 1, 'reference');
        $pattern = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_scanmailbox', 2, 'pattern');
        $content = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'imap_scanmailbox', 3, 'content');
        $names = VmImapCore::listScan($connection, $reference, $pattern, $content, 'imap_scanmailbox');
        if (false === $names) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::stringListToHashTable($names));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_scanmailbox() is not implemented for JIT in this compiler build (issue #27817)');
    }
}

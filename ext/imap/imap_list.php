<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_list() — list mailboxes matching pattern (php-src ext/imap/php_imap.c; #27799). */
final class imap_list extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_list');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_list', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_list');
        $reference = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_list', 1, 'reference');
        $pattern = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_list', 2, 'pattern');
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
        throw new \LogicException('imap_list() is not implemented for JIT in this compiler build (issue #27799)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_rename() — alias of imap_renamemailbox (php-src ext/imap/php_imap.stub.php; #27820). */
final class imap_rename extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_rename');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_rename', 3);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_rename');
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_rename', 1, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_rename', 2, 'to');
        $frame->returnVar->bool(VmImapCore::renameMailbox($connection, $from, $to));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_rename() is not implemented for JIT in this compiler build (issue #27820)');
    }
}

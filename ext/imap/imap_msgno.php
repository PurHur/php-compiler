<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_msgno() — UID → message number (php-src ext/imap/php_imap.c; #27815). */
final class imap_msgno extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_msgno');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_msgno', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_msgno');
        $uid = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_msgno', 2, 'message_uid');
        $frame->returnVar->int(VmImapCore::msgno($connection, $uid));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_msgno() is not implemented for JIT in this compiler build (issue #27815)');
    }
}

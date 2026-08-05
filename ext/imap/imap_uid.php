<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_uid() — message number → UID (php-src ext/imap/php_imap.c; #27815). */
final class imap_uid extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_uid');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_uid', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_uid');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_uid', 2, 'message_num');
        $uid = VmImapCore::uid($connection, $msgNo);
        if (false === $uid) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($uid);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_uid() is not implemented for JIT in this compiler build (issue #27815)');
    }
}

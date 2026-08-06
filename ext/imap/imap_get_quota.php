<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_get_quota() — STORAGE usage/limit for a quota root (php-src ext/imap/php_imap.c; #27816). */
final class imap_get_quota extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_get_quota');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_get_quota', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_get_quota');
        $quotaRoot = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_get_quota', 1, 'quota_root');
        $result = VmImapCore::getQuota($connection, $quotaRoot);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_get_quota() is not implemented for JIT in this compiler build (issue #27816)');
    }
}

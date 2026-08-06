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

/**
 * imap_popen() — persistent open alias of imap_open for local-mbox (#27819).
 *
 * Not present in current php-src stubs; advertised when IMAP is enabled so feature
 * detection matching the filed issue succeeds. Semantics match {@see imap_open}.
 */
final class imap_popen extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_popen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'imap_popen() expects between 3 and 6 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_popen', 0, 'mailbox');
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_popen', 1, 'user');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_popen', 2, 'password');
        $flags = 0;
        if ($argc >= 4) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_popen', 4, 'flags');
        }
        $retries = 0;
        if ($argc >= 5) {
            $retries = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'imap_popen', 5, 'retries');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_popen() requires a VM context');
        }
        $result = VmImapCore::open($mailbox, $user, $password, $flags, $retries, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_popen() is not implemented for JIT in this compiler build (issue #27819)');
    }
}

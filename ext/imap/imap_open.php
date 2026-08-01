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
 * imap_open() — open mailbox (php-src ext/imap/php_imap.c; #3663).
 */
final class imap_open extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'imap_open() expects between 3 and 6 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $mailbox = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'imap_open', 0, 'mailbox');
        $user = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_open', 1, 'user');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_open', 2, 'password');
        $flags = 0;
        if ($argc >= 4) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_open', 4, 'flags');
        }
        $retries = 0;
        if ($argc >= 5) {
            $retries = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'imap_open', 5, 'retries');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_open() requires a VM context');
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
        throw new \LogicException('imap_open() is not implemented for JIT in this compiler build (issue #3663)');
    }
}

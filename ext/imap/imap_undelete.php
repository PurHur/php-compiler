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

/** imap_undelete() — clear deleted marks (php-src ext/imap/php_imap.c; #27783). */
final class imap_undelete extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_undelete');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_undelete', 2, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_undelete');
        $sequence = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_undelete', 1, 'message_nums');
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_undelete', 3, 'flags');
        }
        $frame->returnVar->bool(VmImapCore::undeleteMessages($connection, $sequence, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_undelete() is not implemented for JIT in this compiler build (issue #27783)');
    }
}

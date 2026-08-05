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

/** imap_clearflag_full() — clear message flags (php-src ext/imap/php_imap.c; #27800). */
final class imap_clearflag_full extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_clearflag_full');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_clearflag_full', 3, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_clearflag_full');
        $sequence = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_clearflag_full', 1, 'sequence');
        $flag = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_clearflag_full', 2, 'flag');
        $options = 0;
        if ($argc >= 4) {
            $options = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_clearflag_full', 4, 'options');
        }
        $frame->returnVar->bool(VmImapCore::clearFlagFull($connection, $sequence, $flag, $options));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_clearflag_full() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

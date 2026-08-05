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

/** imap_savebody() — save message body to file (php-src ext/imap/php_imap.c; #27814). */
final class imap_savebody extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_savebody');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_savebody', 3, 5);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_savebody');
        $file = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_savebody', 1, 'file');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_savebody', 3, 'message_num');
        $section = '';
        if ($argc >= 4) {
            $section = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'imap_savebody', 3, 'section');
        }
        $flags = 0;
        if ($argc >= 5) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'imap_savebody', 5, 'flags');
        }
        $frame->returnVar->bool(VmImapCore::saveBody($connection, $file, $msgNo, $section, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_savebody() is not implemented for JIT in this compiler build (issue #27814)');
    }
}

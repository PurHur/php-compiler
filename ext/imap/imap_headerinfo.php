<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_headerinfo() — message headers as object (php-src ext/imap/php_imap.c; #3663). */
final class imap_headerinfo extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_headerinfo');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'imap_headerinfo() expects between 2 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_headerinfo');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_headerinfo', 2, 'message_num');
        $fromLength = 0;
        if ($argc >= 3) {
            $fromLength = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_headerinfo', 3, 'from_length');
        }
        $subjectLength = 0;
        if ($argc >= 4) {
            $subjectLength = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_headerinfo', 4, 'subject_length');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_headerinfo() requires a VM context');
        }
        $result = VmImapCore::headerInfo($connection, $msgNo, $fromLength, $subjectLength, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_headerinfo() is not implemented for JIT in this compiler build (issue #3663)');
    }
}

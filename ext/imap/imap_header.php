<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_header() — alias of imap_headerinfo (php-src ext/imap/php_imap.stub.php; #27820). */
final class imap_header extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_header');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'imap_header() expects between 2 and 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_header');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_header', 2, 'message_num');
        $fromLength = 0;
        if ($argc >= 3) {
            $fromLength = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_header', 3, 'from_length');
        }
        $subjectLength = 0;
        if ($argc >= 4) {
            $subjectLength = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_header', 4, 'subject_length');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_header() requires a VM context');
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
        throw new \LogicException('imap_header() is not implemented for JIT in this compiler build (issue #27820)');
    }
}

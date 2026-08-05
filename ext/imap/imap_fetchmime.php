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

/** imap_fetchmime() — MIME headers for section (php-src ext/imap/php_imap.c; #27814). */
final class imap_fetchmime extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_fetchmime');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_fetchmime', 3, 4);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_fetchmime');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_fetchmime', 2, 'message_num');
        $section = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'imap_fetchmime', 2, 'section');
        $flags = 0;
        if ($argc >= 4) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'imap_fetchmime', 4, 'flags');
        }
        $result = VmImapCore::fetchMime($connection, $msgNo, $section, $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_fetchmime() is not implemented for JIT in this compiler build (issue #27814)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_body() — full message body text (php-src ext/imap/php_imap.c; #27784). */
final class imap_body extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_body');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_body', 2, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_body');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_body', 2, 'message_num');
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_body', 3, 'flags');
        }
        $body = VmImapCore::body($connection, $msgNo, $flags);
        if (false === $body) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($body);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_body() is not implemented for JIT in this compiler build (issue #27784)');
    }
}

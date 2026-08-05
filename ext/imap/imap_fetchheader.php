<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_fetchheader() — raw RFC822 headers (php-src ext/imap/php_imap.c; #27784). */
final class imap_fetchheader extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_fetchheader');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_fetchheader', 2, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_fetchheader');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_fetchheader', 2, 'message_num');
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_fetchheader', 3, 'flags');
        }
        $headers = VmImapCore::fetchHeader($connection, $msgNo, $flags);
        if (false === $headers) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($headers);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_fetchheader() is not implemented for JIT in this compiler build (issue #27784)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_fetchstructure() — full message structure (php-src ext/imap/php_imap.c; #27784). */
final class imap_fetchstructure extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_fetchstructure');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_fetchstructure', 2, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_fetchstructure');
        $msgNo = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_fetchstructure', 2, 'message_num');
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_fetchstructure', 3, 'flags');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_fetchstructure() requires a VM context');
        }
        $result = VmImapCore::fetchStructure($connection, $msgNo, $flags, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_fetchstructure() is not implemented for JIT in this compiler build (issue #27784)');
    }
}

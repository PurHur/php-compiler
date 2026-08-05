<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_thread() — message thread tree (php-src ext/imap/php_imap.c; #27800). */
final class imap_thread extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_thread');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_thread', 1, 2);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_thread');
        $flags = 0;
        if ($argc >= 2) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_thread', 2, 'flags');
        }
        $result = VmImapCore::thread($connection, $flags);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_thread() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

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

/** imap_ping() — check connection alive (php-src ext/imap/php_imap.c; #27783). */
final class imap_ping extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_ping');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_ping', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_ping');
        $frame->returnVar->bool(VmImapCore::ping($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_ping() is not implemented for JIT in this compiler build (issue #27783)');
    }
}

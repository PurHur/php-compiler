<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_check() — mailbox check overview (php-src ext/imap/php_imap.c; #27783). */
final class imap_check extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_check');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_check', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_check');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_check() requires a VM context');
        }
        $result = VmImapCore::check($connection, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_check() is not implemented for JIT in this compiler build (issue #27783)');
    }
}

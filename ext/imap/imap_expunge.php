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

/** imap_expunge() — purge deleted messages (php-src ext/imap/php_imap.c; #27783). */
final class imap_expunge extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_expunge');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_expunge', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_expunge');
        $frame->returnVar->bool(VmImapCore::expunge($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_expunge() is not implemented for JIT in this compiler build (issue #27783)');
    }
}

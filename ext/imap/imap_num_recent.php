<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_num_recent() — recent message count (php-src ext/imap/php_imap.c; #27815). */
final class imap_num_recent extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_num_recent');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_num_recent', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_num_recent');
        $frame->returnVar->int(VmImapCore::numRecent($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_num_recent() is not implemented for JIT in this compiler build (issue #27815)');
    }
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * imap_is_open() — true while stream open; false after close (php-src ext/imap/php_imap.c; #27674).
 *
 * PHP 8.3+; registered when IMAP advertised and language profile ≥ 8.3.
 */
final class imap_is_open extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_is_open');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_is_open', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObjectAllowClosed($frame->calledArgs[0], 'imap_is_open');
        $frame->returnVar->bool(VmImapCore::isLiveConnectionObject($connection));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_is_open() is not implemented for JIT in this compiler build (issue #27674)');
    }
}

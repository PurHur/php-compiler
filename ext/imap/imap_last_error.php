<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_last_error() — last IMAP error string (php-src ext/imap/php_imap.c; #3663). */
final class imap_last_error extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_last_error');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'imap_last_error() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $err = VmImapCore::lastError();
        if (false === $err) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($err);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_last_error() is not implemented for JIT in this compiler build (issue #3663)');
    }
}

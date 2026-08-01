<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_num_msg() — message count (php-src ext/imap/php_imap.c; #3663). */
final class imap_num_msg extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_num_msg');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'imap_num_msg() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_num_msg');
        $n = VmImapCore::numMsg($connection);
        if (false === $n) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($n);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_num_msg() is not implemented for JIT in this compiler build (issue #3663)');
    }
}

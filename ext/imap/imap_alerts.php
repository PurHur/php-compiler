<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** imap_alerts() — drain alert stack (php-src ext/imap/php_imap.c; #27800). */
final class imap_alerts extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_alerts');
    }

    public function execute(Frame $frame): void
    {
        if (0 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'imap_alerts() expects exactly 0 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $alerts = VmImapCore::alerts();
        if (false === $alerts) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::stringListToHashTable($alerts));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_alerts() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

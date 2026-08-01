<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/** imap_search() — search mailbox (php-src ext/imap/php_imap.c; #3663). */
final class imap_search extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_search');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'imap_search() expects between 2 and 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_search');
        $criteria = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_search', 1, 'criteria');
        $hits = VmImapCore::search($connection, $criteria);
        if (false === $hits) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmImapCore::intListToHashTable($hits));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_search() is not implemented for JIT in this compiler build (issue #3663)');
    }
}

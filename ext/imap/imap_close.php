<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_close() — tear down IMAP\Connection (php-src ext/imap/php_imap.c; #3663). */
final class imap_close extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_close');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'imap_close() expects at least 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_close');
        $flags = 0;
        if (2 === $argc) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_close', 2, 'flags');
        }
        $frame->returnVar->bool(VmImapCore::close($connection, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_close() is not implemented for JIT in this compiler build (issue #3663)');
    }
}

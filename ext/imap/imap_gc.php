<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/** imap_gc() — discard cached elements (php-src ext/imap/php_imap.c; #27800). */
final class imap_gc extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_gc');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_gc', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_gc');
        $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_gc', 2, 'flags');
        $frame->returnVar->bool(VmImapCore::gc($connection, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_gc() is not implemented for JIT in this compiler build (issue #27800)');
    }
}

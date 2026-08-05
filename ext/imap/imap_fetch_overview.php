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

/** imap_fetch_overview() — message overview array (php-src ext/imap/php_imap.c; #27784). */
final class imap_fetch_overview extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_fetch_overview');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_fetch_overview', 2, 3);
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $connection = VmImapArg::requireConnectionObject($frame->calledArgs[0], 'imap_fetch_overview');
        $sequence = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'imap_fetch_overview', 1, 'sequence');
        $flags = 0;
        if ($argc >= 3) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'imap_fetch_overview', 3, 'flags');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('imap_fetch_overview() requires a VM context');
        }
        $result = VmImapCore::fetchOverview($connection, $sequence, $flags, $ctx);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_fetch_overview() is not implemented for JIT in this compiler build (issue #27784)');
    }
}

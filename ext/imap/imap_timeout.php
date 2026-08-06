<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * imap_timeout() — get/set c-client open/read/write/close timeouts (php-src ext/imap/php_imap.c; #27680).
 */
final class imap_timeout extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_timeout');
    }

    public function execute(Frame $frame): void
    {
        $this->requireArgCountRange($frame, 'imap_timeout', 1, 2);
        if (null === $frame->returnVar) {
            return;
        }
        $timeoutType = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'imap_timeout', 1, 'timeout_type');
        $timeout = -1;
        if (2 === \count($frame->calledArgs)) {
            $timeout = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'imap_timeout', 2, 'timeout');
        }
        $result = VmImapCore::timeout($timeoutType, $timeout);
        if (\is_int($result)) {
            $frame->returnVar->int($result);
        } else {
            $frame->returnVar->bool($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_timeout() is not implemented for JIT in this compiler build (issue #27680)');
    }
}

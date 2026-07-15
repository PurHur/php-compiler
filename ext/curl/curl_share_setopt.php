<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * curl_share_setopt() — configure shared lock data (php-src ext/curl/interface.c; #6322).
 */
final class curl_share_setopt extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_share_setopt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'curl_share_setopt() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $share = VmCurlArg::requireShareObject($frame->calledArgs[0], 'curl_share_setopt', 1);
        $option = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'curl_share_setopt', 2, 'option');
        $ok = VmCurlShare::setopt($share, $option, $frame->calledArgs[2], $frame);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_share_setopt() is not implemented for JIT in this compiler build (issue #6322)');
    }
}

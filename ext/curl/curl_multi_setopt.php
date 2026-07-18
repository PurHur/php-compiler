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
 * curl_multi_setopt() — set CURLMOPT_* (php-src ext/curl/multi.c; #20495).
 *
 * Signature: curl_multi_setopt(CurlMultiHandle $multi_handle, int $option, mixed $value): bool
 */
final class curl_multi_setopt extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_setopt');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_setopt() expects exactly 3 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_setopt', 1);
        $option = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'curl_multi_setopt', 2, 'option');
        $ok = VmCurlMulti::setopt($multi, $option, $frame->calledArgs[2]);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_setopt() is not implemented for JIT in this compiler build (issue #20495)');
    }
}

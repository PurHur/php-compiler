<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_multi_errno() — last CURLMcode (php-src ext/curl/multi.c; #20495).
 *
 * Signature: curl_multi_errno(CurlMultiHandle $multi_handle): int
 */
final class curl_multi_errno extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_errno');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_errno() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_errno', 1);
        $frame->returnVar->int(VmCurlMulti::errno($multi));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_errno() is not implemented for JIT in this compiler build (issue #20495)');
    }
}

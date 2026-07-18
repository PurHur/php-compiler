<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_reset() — reset easy handle options (php-src ext/curl/interface.c; #20494).
 *
 * Signature: curl_reset(CurlHandle $handle): void
 */
final class curl_reset extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_reset');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_reset() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_reset', 1);
        VmCurlEasy::reset($easy);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_reset() is not implemented for JIT in this compiler build (issue #20494)');
    }
}

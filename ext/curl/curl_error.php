<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_error() — last error string from CURLOPT_ERRORBUFFER
 * (php-src ext/curl/interface.c; #3325, #25814).
 */
final class curl_error extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_error');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_error() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_error', 1);
        $frame->returnVar->string(VmCurlEasy::error($easy));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_error() is not implemented for JIT in this compiler build (issue #3325)');
    }
}

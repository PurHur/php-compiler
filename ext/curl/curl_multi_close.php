<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_multi_close() — cleanup multi handle (php-src ext/curl/multi.c; #3721).
 */
final class curl_multi_close extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_close');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_close() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_close', 1);
        VmCurlMulti::close($multi);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_close() is not implemented for JIT in this compiler build (issue #3721)');
    }
}

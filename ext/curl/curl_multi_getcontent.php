<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_multi_getcontent() — body when CURLOPT_RETURNTRANSFER (php-src ext/curl/multi.c; #3721).
 */
final class curl_multi_getcontent extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_getcontent');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_getcontent() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_multi_getcontent', 1);
        $body = VmCurlMulti::getcontent($easy);
        if (null === $body) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($body);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_getcontent() is not implemented for JIT in this compiler build (issue #3721)');
    }
}

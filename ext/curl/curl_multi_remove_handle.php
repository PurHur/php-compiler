<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_multi_remove_handle() — detach easy from multi (php-src ext/curl/multi.c; #3721).
 */
final class curl_multi_remove_handle extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_multi_remove_handle');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_remove_handle() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        $multi = VmCurlArg::requireMultiObject($frame->calledArgs[0], 'curl_multi_remove_handle', 1);
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[1], 'curl_multi_remove_handle', 2);
        $rc = VmCurlMulti::removeHandle($multi, $easy);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($rc);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_multi_remove_handle() is not implemented for JIT in this compiler build (issue #3721)');
    }
}

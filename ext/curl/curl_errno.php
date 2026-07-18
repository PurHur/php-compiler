<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_errno() — last error code (php-src ext/curl/interface.c; #3325).
 */
final class curl_errno extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_errno');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_errno() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_errno', 1);
        $frame->returnVar->int(VmCurlEasy::errno($easy));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_errno() is not implemented for JIT in this compiler build (issue #3325)');
    }
}

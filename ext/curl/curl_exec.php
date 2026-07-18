<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_exec() — perform transfer (php-src ext/curl/interface.c; #3325).
 */
final class curl_exec extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_exec');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_exec() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_exec', 1);
        $result = VmCurlEasy::exec($easy);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (true === $result) {
            $frame->returnVar->bool(true);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_exec() is not implemented for JIT in this compiler build (issue #3325)');
    }
}

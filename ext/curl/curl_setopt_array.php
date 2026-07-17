<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_setopt_array() — bulk CURLOPT setup (php-src ext/curl/interface.c; #6695).
 */
final class curl_setopt_array extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_setopt_array');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'curl_setopt_array() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_setopt_array', 1);
        $ok = VmCurlEasy::setoptArray($easy, $frame->calledArgs[1], $frame);
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_setopt_array() is not implemented for JIT in this compiler build (issue #6695)');
    }
}

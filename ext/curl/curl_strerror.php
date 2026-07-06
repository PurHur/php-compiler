<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmMath;

/**
 * curl_strerror() — libcurl easy error string (php-src ext/curl/interface.c; #16659).
 */
final class curl_strerror extends CurlFunction
{
    public function __construct()
    {
        parent::__construct('curl_strerror');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_strerror() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $code = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'curl_strerror', 0, 'error_code');
        $message = VmCurlCore::easyStrerror($code);
        if (null === $message) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($message);
        }
    }
}

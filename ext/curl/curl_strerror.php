<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Builtin\CurlStrerrorRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * curl_strerror() — libcurl easy error string (php-src ext/curl/interface.c; #16659, #25813, JIT/AOT #32352).
 *
 * VM delegates to {@see VmCurlNative::easyStrerror()} → curl_easy_strerror() (#25813).
 * JIT/AOT NestedJITs {@see CurlStrerrorJitHelper} (no libcurl FFI in the binary).
 * Reflection stub: int $error_code → ?string (#27810; curl.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
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
        // php-src PHP_FUNCTION(curl_strerror) → curl_easy_strerror; never NULL (#25813).
        $frame->returnVar->string(VmCurlNative::easyStrerror($code));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_strerror() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }

        return CurlStrerrorRuntime::strerror($context, $args[0]);
    }
}

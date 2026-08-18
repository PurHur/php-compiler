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
 * curl_multi_strerror() — libcurl multi error string (php-src ext/curl/interface.c; #16659, JIT/AOT #32352).
 *
 * Reflection stub: int $error_code → ?string (#27810; curl.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
 */
final class curl_multi_strerror extends CurlFunction
{
    public function __construct()
    {
        parent::__construct('curl_multi_strerror');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_strerror() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $code = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'curl_multi_strerror', 0, 'error_code');
        $frame->returnVar->string(VmCurlCore::multiStrerror($code));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_multi_strerror() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }

        return CurlStrerrorRuntime::multiStrerror($context, $args[0]);
    }
}

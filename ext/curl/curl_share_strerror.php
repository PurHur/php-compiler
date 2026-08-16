<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmMath;
use PHPLLVM\Value;

/**
 * curl_share_strerror() — libcurl share error string (php-src ext/curl/share.c; #20531).
 *
 * Reflection stub: int $error_code → ?string (#27810; curl.stub.php via
 * {@see \PHPCompiler\BuiltinInternalArgInfo} / {@see \PHPCompiler\BuiltinParamNames}).
 */
final class curl_share_strerror extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_share_strerror');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_share_strerror() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $code = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'curl_share_strerror', 0, 'error_code');
        $frame->returnVar->string(VmCurlCore::shareStrerror($code));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_share_strerror() is not implemented for JIT in this compiler build (issue #20531)');
    }
}

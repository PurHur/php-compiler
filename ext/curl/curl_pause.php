<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * curl_pause() — pause/resume a transfer (php-src ext/curl/interface.c; #20494).
 *
 * Signature: curl_pause(CurlHandle $handle, int $flags): int
 */
final class curl_pause extends Internal
{
    public function __construct()
    {
        parent::__construct('curl_pause');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'curl_pause() expects exactly 2 arguments, %d given',
                \count($frame->calledArgs)
            ));
        }
        $easy = VmCurlArg::requireEasyObject($frame->calledArgs[0], 'curl_pause', 1);
        $flags = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'curl_pause', 2, 'flags');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmCurlEasy::pause($easy, $flags));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('curl_pause() is not implemented for JIT in this compiler build (issue #20494)');
    }
}

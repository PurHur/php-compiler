<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmMath;

/**
 * CurlHandle::pause() — same semantics as curl_pause() (php-src ext/curl/interface.c; #21837).
 */
final class CurlHandlePause extends CurlClassMethod
{
    public function __construct()
    {
        parent::__construct('pause');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(\sprintf(
                'CurlHandle::pause() expects exactly 1 argument, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $easy = $this->receiver($frame, 'CurlHandle::pause()');
        $flags = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'CurlHandle::pause()', 1, 'flags');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmCurlEasy::pause($easy, $flags));
        }
    }
}

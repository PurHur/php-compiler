<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\Frame;

/**
 * CurlHandle::reset() — same semantics as curl_reset() (php-src ext/curl/interface.c; #21837).
 */
final class CurlHandleReset extends CurlClassMethod
{
    public function __construct()
    {
        parent::__construct('reset');
    }

    public function execute(Frame $frame): void
    {
        $easy = $this->receiver($frame, 'CurlHandle::reset()');
        VmCurlEasy::reset($easy);
    }
}

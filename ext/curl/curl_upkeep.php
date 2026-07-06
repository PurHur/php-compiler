<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl_upkeep() — connection upkeep (php-src ext/curl/interface.c; #16659, #3325).
 *
 * Requires live libcurl handle; full I/O in #3325.
 */
final class curl_upkeep extends CurlFunction
{
    public function __construct()
    {
        parent::__construct('curl_upkeep');
    }
}

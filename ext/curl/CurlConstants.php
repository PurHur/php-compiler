<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * curl extension constants (php-src ext/curl/curl.stub.php; issue #6999).
 *
 * Minimal phase-0 subset; full libcurl surface lands in #3325.
 * CURLINFO_* registers with ext/curl only (#11669) — withheld until #3325.
 */
final class CurlConstants
{
    public const CURLOPT_URL = 10002;
    public const CURLOPT_RETURNTRANSFER = 19913;
    public const CURLOPT_POST = 47;
    public const CURLOPT_HTTPHEADER = 10023;
    public const CURLINFO_HTTP_CODE = 2097154;
    public const CURLINFO_EFFECTIVE_URL = 1048577;
}

<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_last_response_headers() — retired phantom (#28412).
 *
 * php-src only defines http_get_last_response_headers() / http_clear_last_response_headers()
 * (ext/standard/basic_functions.stub.php). Kept unregistered behind
 * CompilerVersion::supportsGetLastResponseHeadersAlias() (always false).
 */
final class get_last_response_headers extends http_get_last_response_headers
{
    public function __construct()
    {
        parent::__construct('get_last_response_headers');
    }
}

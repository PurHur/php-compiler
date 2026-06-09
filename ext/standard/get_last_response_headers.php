<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_last_response_headers() — Zend alias for http_get_last_response_headers() (issue #7236).
 */
final class get_last_response_headers extends http_get_last_response_headers
{
    public function __construct()
    {
        parent::__construct('get_last_response_headers');
    }
}

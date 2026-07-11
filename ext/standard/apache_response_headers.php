<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * apache_response_headers() — Zend alias for headers_list() (ext/standard/head.c, issue #6260).
 */
final class apache_response_headers extends header_list
{
    public function __construct()
    {
        parent::__construct('apache_response_headers');
    }
}

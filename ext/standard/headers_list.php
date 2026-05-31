<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * headers_list() — Zend alias for pending response headers (ext/standard/head.c, issue #3499).
 */
final class headers_list extends header_list
{
    public function __construct()
    {
        parent::__construct('headers_list');
    }
}


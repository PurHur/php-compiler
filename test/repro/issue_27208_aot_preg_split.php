<?php

declare(strict_types=1);

/**
 * Issue #27208 — thin AOT preg_split must materialize string parts for implode.
 * php-src: ext/pcre/php_pcre.c PHP_FUNCTION(preg_split)
 */
echo implode(',', preg_split('/\s+/', 'a  b c')), "\n";

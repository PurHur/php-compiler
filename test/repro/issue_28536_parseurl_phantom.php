<?php
/**
 * Repro for #28536 — ParseUrl phantom absent under PROFILE≥8.4.
 * php-src never ships ParseUrl; component selection remains PHP_URL_* ints.
 */
echo 'ParseUrl=', enum_exists('ParseUrl') ? 'Y' : 'N', "\n";
echo parse_url('http://example.com/path', PHP_URL_HOST), "\n";

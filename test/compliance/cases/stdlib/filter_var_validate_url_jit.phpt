--TEST--
stdlib filter_var() FILTER_VALIDATE_URL JIT (#11274)
--FILE--
<?php
declare(strict_types=1);
var_export(filter_var('https://example.com', FILTER_VALIDATE_URL));
echo "\n";
var_export(filter_var('http://127.0.0.1:8080/path?q=1#frag', FILTER_VALIDATE_URL));
echo "\n";
var_export(filter_var('ftp://example.com', FILTER_VALIDATE_URL));
echo "\n";
var_export(filter_var('not a url', FILTER_VALIDATE_URL));
echo "\n";
--EXPECT--
'https://example.com'
'http://127.0.0.1:8080/path?q=1#frag'
'ftp://example.com'
false

--TEST--
stdlib parse_url() missing component returns false (php-src ext/standard/url.c)
--FILE--
<?php
echo parse_url('http://', 1) === false ? 'false' : 'not-false', "\n";
echo parse_url('http://example.com', 2) === false ? 'false' : 'not-false', "\n";
echo parse_url('/only-path', 0) === false ? 'false' : 'not-false', "\n";
echo parse_url('http://', 5), "\n";
--EXPECT--
false
false
false

--TEST--
stdlib ParseUrl phantom absent; parse_url() PHP_URL_* ints (#28536, re-#7260)
--FILE--
<?php
var_export(enum_exists('ParseUrl', false));
echo "\n";
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, PHP_URL_HOST), "\n";
echo parse_url($url, component: PHP_URL_PATH), "\n";
echo parse_url($url, PHP_URL_PORT), "\n";
echo parse_url($url, PHP_URL_USER), "\n";
--EXPECT--
false
example.com
/path
8080
user

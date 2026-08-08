--TEST--
stdlib ParseUrl phantom absent; parse_url() JIT PHP_URL_* (#28536, re-#7260)
--JIT--
--FILE--
<?php
echo enum_exists('ParseUrl', false) ? 'enum' : 'noenum', "\n";
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, PHP_URL_HOST), "\n";
echo parse_url($url, component: PHP_URL_PATH), "\n";
echo parse_url($url, PHP_URL_PORT), "\n";
--EXPECT--
noenum
example.com
/path
8080

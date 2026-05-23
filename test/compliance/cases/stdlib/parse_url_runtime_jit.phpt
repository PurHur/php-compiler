--TEST--
stdlib parse_url() JIT on runtime REQUEST_URI (issue #311 wave 3)
--ENV--
REQUEST_URI=/hello?name=World
--FILE--
<?php
$uri = $_SERVER['REQUEST_URI'] ?? '';
echo parse_url($uri, PHP_URL_PATH), "\n";
echo parse_url($uri, PHP_URL_QUERY), "\n";
echo parse_url('http://example.com:8080/app?q=1#frag', PHP_URL_HOST), "\n";
--EXPECT--
/hello
name=World
example.com

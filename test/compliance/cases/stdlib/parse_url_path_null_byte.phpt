--TEST--
stdlib parse_url() replaces NUL in path with underscore (#13553, ext/standard/url.c)
--FILE--
<?php
$url = 'http://example.com/a' . "\0" . 'b';
$path = parse_url($url, PHP_URL_PATH);
echo strlen($path) . ':' . bin2hex($path), "\n";
--EXPECT--
4:2f615f62

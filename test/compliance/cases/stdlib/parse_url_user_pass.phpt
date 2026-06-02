--TEST--
stdlib parse_url() PHP_URL_USER and PHP_URL_PASS components
--FILE--
<?php
echo parse_url('http://user:pass@host/path', PHP_URL_USER) ?? 'null';
echo "\n";
echo parse_url('http://user:pass@host/path', PHP_URL_PASS) ?? 'null';
echo "\n";
echo parse_url('http://host/path', PHP_URL_USER) ?? 'null';
echo "\n";
$parts = parse_url('http://user:pass@host:8080/app?q=1#frag');
echo $parts['user'], "\n";
echo $parts['pass'], "\n";
echo $parts['host'], "\n";
echo parse_url('http://user:p:a:s:s@host/path', PHP_URL_PASS) ?? 'null';
echo "\n";
--EXPECT--
user
pass
null
user
pass
host
p:a:s:s

--TEST--
stdlib ParseUrl enum — parse_url() component (#7260, ext/standard/basic_functions.stub.php)
--FILE--
<?php
var_export(enum_exists('ParseUrl', false));
echo "\n";
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, ParseUrl::Host), "\n";
echo parse_url($url, component: ParseUrl::Path), "\n";
echo parse_url($url, ParseUrl::Port), "\n";
echo parse_url($url, PHP_URL_USER), "\n";
--EXPECT--
true
example.com
/path
8080
user

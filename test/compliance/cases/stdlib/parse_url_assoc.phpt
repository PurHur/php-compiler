--TEST--
stdlib parse_url() associative array VM (no component)
--FILE--
<?php
$parts = parse_url('/hello?name=World');
echo $parts['path'], "\n";
echo $parts['query'], "\n";
$full = parse_url('http://example.com:8080/app?q=1#frag');
echo $full['scheme'], "\n";
echo $full['host'], "\n";
echo $full['port'], "\n";
echo $full['path'], "\n";
echo $full['query'], "\n";
echo $full['fragment'], "\n";
--EXPECT--
/hello
name=World
http
example.com
8080
/app
q=1
frag

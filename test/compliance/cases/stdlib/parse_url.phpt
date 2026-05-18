--TEST--
stdlib parse_url() path and query components
--FILE--
<?php
$path = parse_url('/hello?name=World', 5);
$query = parse_url('/hello?name=World', 6);
echo $path, "\n";
echo $query, "\n";
$parts = parse_url('http://example.com:8080/app?q=1#frag');
echo $parts['host'], "\n";
echo $parts['path'], "\n";
echo $parts['query'], "\n";
--EXPECT--
/hello
name=World
example.com
/app
q=1

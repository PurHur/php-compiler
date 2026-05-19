--TEST--
stdlib parse_url() JIT/AOT path and query components
--FILE--
<?php
$path = parse_url('/hello?name=World', 5);
$query = parse_url('/hello?name=World', 6);
echo $path, "\n";
echo $query, "\n";
echo parse_url('http://example.com:8080/app?q=1#frag', 1), "\n";
echo parse_url('http://example.com:8080/app?q=1#frag', 5), "\n";
echo parse_url('http://example.com:8080/app?q=1#frag', 6), "\n";
--EXPECT--
/hello
name=World
example.com
/app
q=1

--TEST--
AOT parse_url() invalid port → false; empty user/pass keys (#22822)
--FILE--
<?php
echo parse_url('http://ex.com:port/') === false ? 'false' : 'not-false', "\n";
echo parse_url('http://ex.com:99999/') === false ? 'false' : 'not-false', "\n";
$parts = parse_url('http://user:@h/');
echo $parts['user'], '|', strlen($parts['pass']), "\n";
$parts = parse_url('http://:pass@h/');
echo strlen($parts['user']), '|', $parts['pass'], "\n";
echo parse_url('http://ex.com:0/')['port'], "\n";
--EXPECT--
false
false
user|0
0|pass
0

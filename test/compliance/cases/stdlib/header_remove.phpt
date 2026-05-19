--TEST--
stdlib header_remove() and header_list() (issue #311)
--FILE--
<?php
header('X-Test: 1');
header_remove('X-Test');
echo count(header_list()), "\n";
header('Content-Type: application/json');
header_remove('Content-Type');
header('Content-Type: text/plain');
echo count(header_list()), "\n";
echo header_list()[0], "\n";
header_remove();
echo count(header_list()), "\n";
--EXPECT--
0
1
Content-Type: text/plain
0

--TEST--
AOT: headers_list() empty under CLI (issues #3499, #4037)
--FILE--
<?php
header('X-A: 1');
header('X-B: 2');
echo count(headers_list()), "\n";
--EXPECT--
0
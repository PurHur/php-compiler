--TEST--
stdlib headers_list() empty under CLI after header() (issues #3499, #4037)
--FILE--
<?php
header('X-Test: one');
header('X-Test: two', false);
echo count(headers_list()), "\n";
--EXPECT--
0
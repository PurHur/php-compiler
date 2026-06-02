--TEST--
stdlib headers_list() after header() (issue #3499)
--FILE--
<?php
header('X-Test: one');
header('X-Test: two', false);
echo count(headers_list()), "\n";
echo headers_list()[0], "\n";
echo headers_list()[1], "\n";
--EXPECT--
2
X-Test: one
X-Test: two
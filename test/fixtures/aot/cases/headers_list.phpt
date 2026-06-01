--TEST--
AOT: headers_list() pending queue (issue #3499)
--FILE--
<?php
header('X-A: 1');
header('X-B: 2');
echo count(headers_list()), "\n";
echo headers_list()[0], "\n";
--EXPECT--
X-A: 1
X-B: 2
2
X-A: 1
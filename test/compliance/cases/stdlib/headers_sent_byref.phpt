--TEST--
stdlib headers_sent($file, $line) by-ref output origin (issue #5134)
--RUNFILE--
headers_sent_byref.inc.php
--EXPECT--
body
true
2
headers_sent_byref.inc.php
--EXPECT_EXIT--
0

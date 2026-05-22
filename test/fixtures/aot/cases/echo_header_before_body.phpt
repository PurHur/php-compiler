--TEST--
AOT: deferred header() flushes before native-string echo (issue #634)
--FILE--
<?php
header('Content-Type: text/plain; charset=UTF-8');
echo 'name=', 'Alice';
--EXPECT--
Content-Type: text/plain; charset=UTF-8
name=Alice
--EXPECT_EXIT--
0

--TEST--
AOT get_class() on stream — TypeError not Resource pseudo-class (#12840)
--FILE--
<?php
$stream = fopen('php://memory', 'r+');
get_class($stream);
--EXPECT--
--EXPECT_EXIT--
134

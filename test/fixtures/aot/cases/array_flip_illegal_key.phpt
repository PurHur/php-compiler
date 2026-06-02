--TEST--
AOT array_flip() — object array keys fatal at array literal (#4268)
--FILE--
<?php
$o = new stdClass();
array_flip([$o => 1]);
--EXPECT--
--EXPECT_EXIT--
255

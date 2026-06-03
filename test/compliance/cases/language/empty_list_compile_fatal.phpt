--TEST--
Language: empty list assignment — compile-time fatal (#4525)
--FILE--
<?php
list() = [];
echo "ran\n";
--EXPECT_EXIT--
255

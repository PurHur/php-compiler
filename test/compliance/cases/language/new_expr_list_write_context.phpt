--TEST--
Language: list destructuring on new property — compile-time fatal (#6691)
--FILE--
<?php
list((new stdClass())->a) = [1];
echo "ran\n";
--EXPECT_EXIT--
255

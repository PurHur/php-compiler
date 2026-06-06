--TEST--
Language: new expression in write context — compile-time fatal (#6691)
--FILE--
<?php
(new stdClass())->x = 1;
echo "ran\n";
--EXPECT_EXIT--
255

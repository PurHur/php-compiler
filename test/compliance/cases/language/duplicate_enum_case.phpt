--TEST--
Language: duplicate enum case — compile-time fatal (#5218)
--FILE--
<?php
enum E {
    case A;
    case A;
}
echo "run\n";
--EXPECT_EXIT--
255

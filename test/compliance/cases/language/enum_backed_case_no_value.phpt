--TEST--
Language: backed enum case without value — compile-time fatal (#5397)
--FILE--
<?php
enum E: string {
    case A;
}
echo "ok\n";
--EXPECT_EXIT--
255

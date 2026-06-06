--TEST--
Language: enum cannot define __construct — compile-time fatal (#6867)
--FILE--
<?php
enum E {
    case A;
    public function __construct() {}
}
echo "ok\n";
--EXPECT_EXIT--
255

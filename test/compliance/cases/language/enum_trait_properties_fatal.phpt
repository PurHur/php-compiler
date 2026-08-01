--TEST--
Language: enum use trait with properties — compile-time fatal (#26558, re-#6005, zend_compile.c)
--FILE--
<?php
trait T {
    public $x = 1;
}
enum E {
    use T;
    case A;
}
echo "ok\n";
--EXPECT_EXIT--
255

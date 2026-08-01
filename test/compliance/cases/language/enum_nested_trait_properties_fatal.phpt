--TEST--
Language: enum use nested trait with properties — compile-time fatal (#26558, zend_traits.c)
--FILE--
<?php
trait Inner {
    public $x = 1;
}
trait Outer {
    use Inner;
}
enum E {
    use Outer;
    case A;
}
echo "ok\n";
--EXPECT_EXIT--
255

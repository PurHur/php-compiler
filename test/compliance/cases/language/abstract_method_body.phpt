--TEST--
Language: abstract method with body — compile fatal (#22927)
--FILE--
<?php
abstract class A {
    abstract function f() {
        return 1;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255

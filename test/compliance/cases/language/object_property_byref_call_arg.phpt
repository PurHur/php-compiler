--TEST--
language: object property passed by reference to user function (#25301, Zend/zend_execute.c ZEND_SEND_REF)
--FILE--
<?php
class C {
    public int $prop = 1;
}
$obj = new C();
$ref = &$obj->prop;
$ref = 5;
echo $obj->prop, "\n";

function bump(int &$x): void {
    $x = 9;
}
$obj->prop = 1;
bump($obj->prop);
echo $obj->prop, "\n";
--EXPECT--
5
9
--CREDITS--
PurHur/php-compiler issue #25301

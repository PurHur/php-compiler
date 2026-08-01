--TEST--
Language: promoted callable property type — compile fatal (#26516, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public callable $c) {}
}
echo "ok\n";
--EXPECT_EXIT--
255

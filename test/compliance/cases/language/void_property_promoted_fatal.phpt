--TEST--
Language: promoted void property type — compile fatal (#26518, zend_compile.c)
--FILE--
<?php
class C {
    public function __construct(public void $p) {}
}
echo "ok\n";
--EXPECT_EXIT--
255

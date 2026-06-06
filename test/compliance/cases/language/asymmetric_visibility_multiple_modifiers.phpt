--TEST--
Language: asymmetric visibility — explicit public before private(set) compile fatal (#6774, zend_compile.c)
--FILE--
<?php
class C {
    public private(set) string $x = 'a';
}
echo "ok\n";
--EXPECT_EXIT--
255

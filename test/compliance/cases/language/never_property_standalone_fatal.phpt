--TEST--
Language: standalone never property type — compile fatal (#7052, zend_compile.c)
--FILE--
<?php
class C {
    public never $p;
}
echo "ok\n";
--EXPECT_EXIT--
255

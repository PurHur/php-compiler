--TEST--
Language: standalone void property type — compile fatal (#26518, zend_compile.c)
--FILE--
<?php
class C {
    public void $p;
}
echo "ok\n";
--EXPECT_EXIT--
255

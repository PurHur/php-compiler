--TEST--
Language: never in declared property union — compile fatal (#6967, zend_compile.c)
--FILE--
<?php
class C {
    public int|never $x;
}
echo "compiled\n";
--EXPECT_EXIT--
255

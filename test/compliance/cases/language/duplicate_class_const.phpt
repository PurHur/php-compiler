--TEST--
Language: duplicate class constants — compile-time fatal (#5219, zend_compile.c)
--FILE--
<?php
class C {
    public const X = 1;
    public const X = 2;
}
echo "run\n";
--EXPECT_EXIT--
255

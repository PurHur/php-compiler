--TEST--
Language: static property hooks must compile-error (#6901, #6619, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static int $x {
        get => 1;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255

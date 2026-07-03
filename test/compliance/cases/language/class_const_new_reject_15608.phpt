--TEST--
Language: class constant `new` expression rejected (#15608, Zend/zend_compile.c)
--FILE--
<?php

declare(strict_types=1);

class Holder {
    public const OBJ = new \stdClass();
}

echo get_class(Holder::OBJ), "\n";
--EXPECT_EXIT--
255

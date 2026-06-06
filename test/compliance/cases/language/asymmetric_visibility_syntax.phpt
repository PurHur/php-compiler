--TEST--
Language: asymmetric visibility syntax public private(set) — compile-time fatal (#6861, #7099, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Asym {
    public private(set) string $name = 'x';
}
echo "compiled\n";
--EXPECT_EXIT--
255

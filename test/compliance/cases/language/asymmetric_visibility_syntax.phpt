--TEST--
Language: asymmetric visibility syntax public private(set) (#6861, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Asym {
    public private(set) string $name = 'x';
}
$a = new Asym();
echo $a->name, "\n";
$a->name = 'y';
--EXPECT--
x
--EXPECT_EXIT--
255

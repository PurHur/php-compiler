--TEST--
Language: asymmetric visibility syntax public private(set) compile fatal (#7388, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Asym {
    public private(set) string $name = 'x';
}
echo $name = (new Asym())->name, "\n";
--EXPECT_EXIT--
255

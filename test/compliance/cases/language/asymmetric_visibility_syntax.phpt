--TEST--
Language: asymmetric visibility syntax private(set) compiles (#7460, #9161, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Asym {
    private(set) string $name = 'x';
}
echo (new Asym())->name, "\n";
--EXPECT--
x

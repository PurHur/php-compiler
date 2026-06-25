--TEST--
Language: parenthesized public (private(set)) compiles (#11546, PHP 8.4 zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Demo {
    public (private(set)) string $name = 'x';
}
echo (new Demo())->name, "\n";
--EXPECT--
x

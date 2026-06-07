--TEST--
Language: PHP 8.4 parenthesized asymmetric visibility public (private(set)) compiles (#7308)
--FILE--
<?php
declare(strict_types=1);

class Demo {
    public (private(set)) string $name = 'x';
}
echo (new Demo())->name, "\n";
--EXPECT--
x

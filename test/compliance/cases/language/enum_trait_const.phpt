--TEST--
Language: enum trait constants — E::X from trait use must resolve (zend_traits.c, zend_enum.c, #5719)
--FILE--
<?php
declare(strict_types=1);

trait T {
    public const X = 1;
}

enum E {
    use T;
}

echo E::X, "\n";
--EXPECT--
1

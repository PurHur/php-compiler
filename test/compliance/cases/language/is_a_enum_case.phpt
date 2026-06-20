--TEST--
Language: is_a() on enum case object — must accept enum not TypeError on int (#10217, Zend/zend_type.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int {
    case A = 1;
}

echo is_a(E::A, E::class, true) ? '1' : '0';
echo is_a(E::A, 'BackedEnum', true) ? '1' : '0';
echo is_subclass_of(E::A, E::class, true) ? '1' : '0';
echo "\n";
--EXPECT--
110

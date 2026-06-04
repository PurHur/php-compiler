--TEST--
Language: (array) cast skips uninitialized typed properties (#5398, zend_object_handlers.c)
--FILE--
<?php
class C {
    public int $x;
    public string $y = 'hi';
}
$c = new C();
$a = (array) $c;
echo count($a);
echo array_key_exists('x', $a) ? '1' : '0';
echo array_key_exists('y', $a) ? '1' : '0';
echo "\n";
--EXPECT--
101

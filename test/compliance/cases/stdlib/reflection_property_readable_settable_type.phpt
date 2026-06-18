--TEST--
ReflectionProperty::getReadableType()/getSettableType() asymmetric typed property (#7053)
--FILE--
<?php
declare(strict_types=1);

class C {
    private(set) int $x = 1;
    private(set) string $p = 'x';
    public int $plain = 0;
}

$p = new ReflectionProperty(C::class, 'x');
echo 'x_readable_exists=', method_exists($p, 'getReadableType') ? '1' : '0', "\n";
echo 'x_settable_exists=', method_exists($p, 'getSettableType') ? '1' : '0', "\n";
echo 'x_readable=', (string) $p->getReadableType(), "\n";
echo 'x_settable=', (string) $p->getSettableType(), "\n";

$q = new ReflectionProperty(C::class, 'p');
echo 'p_readable=', (string) $q->getReadableType(), "\n";
echo 'p_settable=', (string) $q->getSettableType(), "\n";

$plain = new ReflectionProperty(C::class, 'plain');
echo 'plain_readable=', (string) $plain->getReadableType(), "\n";
echo 'plain_settable=', (string) $plain->getSettableType(), "\n";
--EXPECT--
x_readable_exists=1
x_settable_exists=1
x_readable=int
x_settable=int
p_readable=string
p_settable=string
plain_readable=int
plain_settable=int

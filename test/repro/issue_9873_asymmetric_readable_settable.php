<?php

declare(strict_types=1);

class C {
    public private(set) int $x = 1;
    public private(set) string $p = 'x';
    public int $plain = 0;
}

$p = new ReflectionProperty(C::class, 'x');
echo 'x_readable=', (string) $p->getReadableType(), "\n";
echo 'x_settable=', (string) $p->getSettableType(), "\n";

$q = new ReflectionProperty(C::class, 'p');
echo 'p_readable=', (string) $q->getReadableType(), "\n";
echo 'p_settable=', (string) $q->getSettableType(), "\n";

$plain = new ReflectionProperty(C::class, 'plain');
echo 'plain_readable=', (string) $plain->getReadableType(), "\n";
echo 'plain_settable=', (string) $plain->getSettableType(), "\n";

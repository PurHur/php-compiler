<?php

declare(strict_types=1);

class T
{
    public int|string $x;
}

$t = new T();
try {
    $t->x = null;
    echo "assigned='", $t->x, "'\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}

$t->x = 0;
echo "int=", $t->x, "\n";
$t->x = 'ok';
echo "str=", $t->x, "\n";

class N
{
    public int|string|null $y;
}

$n = new N();
$n->y = null;
echo 'nullable=', var_export($n->y, true), "\n";

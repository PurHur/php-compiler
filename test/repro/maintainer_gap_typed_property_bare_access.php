<?php

declare(strict_types=1);

class T
{
    public int $x;
}

class S
{
    public static int $s;
}

$t = new T();
try {
    $t->x;
    echo "instance_bare_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    S::$s;
    echo "static_bare_ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

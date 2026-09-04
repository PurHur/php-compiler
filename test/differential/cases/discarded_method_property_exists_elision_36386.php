<?php

declare(strict_types=1);

/**
 * Discarded method_exists / property_exists must not change observable output (#36386).
 *
 * php-src: Zend/zend_builtin_functions.c (method_exists, property_exists)
 */

class Node
{
    public int $x = 0;

    public function bump(): void
    {
        $this->x++;
    }
}

function work(string $m, string $p): string
{
    $o = new Node();
    method_exists($o, $m);
    property_exists($o, $p);

    $a = method_exists($o, 'bump') ? '1' : '0';
    $b = method_exists($o, 'nope') ? '1' : '0';
    $c = property_exists($o, 'x') ? '1' : '0';
    $d = property_exists($o, 'nope') ? '1' : '0';

    return $a.$b.$c.$d;
}

echo work('bump', 'x'), "\n";
echo work('nope', 'nope'), "\n";

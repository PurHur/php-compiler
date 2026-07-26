<?php

declare(strict_types=1);

/**
 * Repro for #23336 — __call / __callStatic must accept named args and named unpack.
 *
 * Zend packs string keys into the magic `$arguments` array; php-compiler must not
 * reject them as "Unknown named parameter".
 */
class A
{
    public function __call($n, $a)
    {
        echo "call $n ";
        var_export($a);
        echo "\n";
    }

    public static function __callStatic($n, $a)
    {
        echo "static $n ";
        var_export($a);
        echo "\n";
    }
}

$a = new A();
$a->bar(1, 2);
$a->qux(x: 1, y: 3);
A::qux(x: 1);
$a->qux(...['x' => 1, 'y' => 2]);

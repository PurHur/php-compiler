<?php

declare(strict_types=1);

/**
 * Zend vs php-compiler: settype($object, 'array') uses property hash (#9963, ext/standard/type.c).
 */

class C
{
    public int $a = 1;

    private int $b = 2;
}

$o = new stdClass();
settype($o, 'array');
var_export($o);
echo "\n";

$c = new C();
settype($c, 'array');
var_export($c);
echo "\n";

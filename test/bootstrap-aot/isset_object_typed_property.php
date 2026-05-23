<?php

declare(strict_types=1);

/**
 * isset() on object-typed property with array offset (JIT IssetHelper, issue #764).
 */

class Holder
{
    public object $map;
}

$h = new Holder();
$h->map = ['key' => 1];
echo isset($h->map['key']) ? "1\n" : "0\n";

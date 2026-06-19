<?php

declare(strict_types=1);

/**
 * Issue #10123 — typed property ++/-- must return old/new value (Zend/zend_execute.c).
 */

class C {
    public int $x = 1;
    public static int $s = 1;
}

function static_local(): void
{
    static $n = 1;
    var_export($n++);
    echo "\n";
}

$c = new C();
var_export($c->x++);
echo "\n";
var_export(C::$s++);
echo "\n";
static_local();

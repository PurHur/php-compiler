--TEST--
stdlib var_export($expr ?? null, true) — return string not echo (#9457, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);
$a = [];
$s = var_export($a[0] ?? null, true);
echo gettype($s), "\n", $s, "\n";
--EXPECT--
string
NULL

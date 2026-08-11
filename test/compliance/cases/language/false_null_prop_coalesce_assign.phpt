--TEST--
Language: $false|$null->prop ??= emits assign Error only (#30120)
--FILE--
<?php
error_reporting(E_ALL);

$f = false;
try {
    $f->x ??= 1;
} catch (Throwable $e) {
    echo 'FALSE:', get_class($e), ': ', $e->getMessage(), "\n";
}

$n = null;
try {
    $n->x ??= 1;
} catch (Throwable $e) {
    echo 'NULL:', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
FALSE:Error: Attempt to assign property "x" on false
NULL:Error: Attempt to assign property "x" on null

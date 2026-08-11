--TEST--
Language: property ++/-- on true|false uses increment/decrement + true/false (#30075)
--FILE--
<?php
error_reporting(E_ALL);

$x = false;
try {
    $x->a++;
} catch (Throwable $e) {
    echo 'POST_INC:', $e->getMessage(), "\n";
}
$x = true;
try {
    $x->a--;
} catch (Throwable $e) {
    echo 'POST_DEC:', $e->getMessage(), "\n";
}
$x = false;
try {
    ++$x->a;
} catch (Throwable $e) {
    echo 'PRE_INC:', $e->getMessage(), "\n";
}
$x = true;
try {
    --$x->a;
} catch (Throwable $e) {
    echo 'PRE_DEC:', $e->getMessage(), "\n";
}
$x = null;
try {
    $x->a++;
} catch (Throwable $e) {
    echo 'NULL:', $e->getMessage(), "\n";
}
$x = 1;
try {
    $x->a++;
} catch (Throwable $e) {
    echo 'INT:', $e->getMessage(), "\n";
}
--EXPECT--
POST_INC:Attempt to increment/decrement property "a" on false
POST_DEC:Attempt to increment/decrement property "a" on true
PRE_INC:Attempt to increment/decrement property "a" on false
PRE_DEC:Attempt to increment/decrement property "a" on true
NULL:Attempt to increment/decrement property "a" on null
INT:Attempt to increment/decrement property "a" on int

--TEST--
Language: PHP 8.4 closure TypeError includes {closure:fn():line} (#30076)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
function outer() {
    $f = function (int $x): int { return $x; };
    try {
        $f(null);
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";
    }
    $g = function (): int { return null; };
    try {
        $g();
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";
    }
}
outer();
$h = fn (): int => null;
try {
    $h();
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
{closure:outer():3}(): Argument #1 ($x) must be of type int, null given, called in %s on line %d
{closure:outer():9}(): Return value must be of type int, null returned
{closure:%s:17}(): Return value must be of type int, null returned

<?php
/**
 * #22088 — ReflectionFunction::invokeArgs(array $args) (ext/reflection/php_reflection.c)
 */
function add($a, $b)
{
    return $a + $b;
}
function double($a)
{
    return $a * 2;
}
$rf = new ReflectionFunction('add');
echo $rf->invokeArgs([2, 3]), "\n";
$rd = new ReflectionFunction('double');
echo $rd->invoke(4), "\n";

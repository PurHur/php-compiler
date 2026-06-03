<?php
function f(...$args)
{
    return $args;
}
var_export(f(a: 1, b: 2));
echo "\n";

function g($x, ...$args)
{
    return [$x, $args];
}
var_export(g(x: 1, a: 2, b: 3));
echo "\n";
var_export(g(1, b: 2));
echo "\n";

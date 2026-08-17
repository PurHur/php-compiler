<?php
/**
 * Nested ?? on uninitialized static typed properties (concat form).
 */
error_reporting(E_ALL);

class S
{
    public static int $y;
}

echo "static=";
var_export(S::$y ?? "d");
echo "\n";
echo "static_concat=" . var_export(S::$y ?? "d", true) . "\n";
echo "after\n";

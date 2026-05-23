<?php
declare(strict_types=1);
$n = intval("42"); $b = boolval($n); $f = floatval($b); $items = ["a", "b", "c"];
echo (string) ($n + intval($f));
echo is_int($n) ? "1" : "0"; echo is_string("x") ? "1" : "0"; echo is_array($items) ? "1" : "0";
echo is_null(null) ? "1" : "0"; echo is_bool($b) ? "1" : "0"; echo is_float($f) ? "1" : "0";
echo (string) count($items); echo (string) strlen("abc"); echo is_numeric("3.14") ? "1" : "0"; echo sprintf("%d", $n);

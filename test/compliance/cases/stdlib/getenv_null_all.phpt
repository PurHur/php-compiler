--TEST--
stdlib getenv(null) — full environ array (#11943, ext/standard/basic_functions.c)
--FILE--
<?php
$all = getenv(null);
echo is_array($all) ? "array_ok\n" : "array_fail\n";
$found = false;
if (array_key_exists('PATH', $all)) {
    $found = true;
}
if (!$found && array_key_exists('HOME', $all)) {
    $found = true;
}
echo $found ? "has_key\n" : "missing_key\n";
--EXPECT--
array_ok
has_key

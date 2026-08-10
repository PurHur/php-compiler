--TEST--
AOT mb_detect_order(null) returns current order under strict_types (#29920)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
// Thin AOT cannot var_export() arrays (#26855); print scalars.
$o = mb_detect_order(null);
echo is_array($o) ? 'array' : gettype($o), ':', count($o), ':', implode(',', $o), "\n";
?>
--EXPECT--
array:2:ASCII,UTF-8

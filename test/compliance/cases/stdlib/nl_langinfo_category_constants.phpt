--TEST--
stdlib nl_langinfo category constants DECIMAL_POINT GROUPING THOUSANDS_SEP (#17783, ext/standard/locale.c)
--FILE--
<?php
declare(strict_types=1);

var_export(defined('DECIMAL_POINT') && DECIMAL_POINT === 65536);
echo "\n";
var_export(defined('GROUPING') && GROUPING === 65538);
echo "\n";
var_export(defined('THOUSANDS_SEP') && THOUSANDS_SEP === 65537);
echo "\n";
$item = defined('DECIMAL_POINT') ? nl_langinfo(DECIMAL_POINT) : false;
var_export(is_string($item) && '' !== $item);
echo "\n";
--EXPECT--
true
true
true
true

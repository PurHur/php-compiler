--TEST--
stdlib array_find family — withheld on default 8.4.0-dev reference (#30238 / #24821, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
?>
--EXPECT--
array_find=false
array_find_key=false
array_any=false
array_all=false

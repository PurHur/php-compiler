--TEST--
stdlib array_any_key()/array_all_key() phantom on PHP 8.4 forward profile (#24000, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['array_any', 'array_all', 'array_any_key', 'array_all_key'] as $fn) {
    echo $fn, ' exists=', function_exists($fn) ? 'yes' : 'no', "\n";
}

$a = ['x' => 1, 'y' => 2, 'z' => 3];
echo 'any_empty=', array_any([], fn () => true) ? 'T' : 'F', "\n";
echo 'all_empty=', array_all([], fn () => false) ? 'T' : 'F', "\n";
echo 'any=', array_any([1, 2, 3], fn ($v) => $v === 2) ? 'T' : 'F', "\n";
echo 'all=', array_all([1, 2, 3], fn ($v) => $v > 0) ? 'T' : 'F', "\n";
echo 'find_key=', array_find_key($a, fn ($v, $k) => $k === 'y'), "\n";
--EXPECT--
array_any exists=yes
array_all exists=yes
array_any_key exists=no
array_all_key exists=no
any_empty=F
all_empty=T
any=T
all=T
find_key=y

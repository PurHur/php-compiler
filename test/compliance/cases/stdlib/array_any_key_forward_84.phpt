--TEST--
stdlib array_any()/array_all()/array_any_key()/array_all_key() — PHP 8.4 forward profile (#16988, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['array_any', 'array_all', 'array_any_key', 'array_all_key'] as $fn) {
    echo $fn, ' exists=', function_exists($fn) ? 'yes' : 'no', "\n";
}

$a = ['x' => 1, 'y' => 2, 'z' => 3];
echo 'any_key_y=', array_any_key($a, fn ($k, $v) => $k === 'y' && $v === 2) ? 'T' : 'F', "\n";
echo 'all_key=', array_all_key($a, fn ($k, $v) => is_string($k) && $v > 0) ? 'T' : 'F', "\n";
echo 'any_empty=', array_any([], fn () => true) ? 'T' : 'F', "\n";
echo 'all_empty=', array_all([], fn () => false) ? 'T' : 'F', "\n";
echo 'any_key_empty=', array_any_key([], fn () => true) ? 'T' : 'F', "\n";
echo 'all_key_empty=', array_all_key([], fn () => false) ? 'T' : 'F', "\n";
echo 'any=', array_any([1, 2, 3], fn ($v) => $v === 2) ? 'T' : 'F', "\n";
echo 'all=', array_all([1, 2, 3], fn ($v) => $v > 0) ? 'T' : 'F', "\n";
--EXPECT--
array_any exists=yes
array_all exists=yes
array_any_key exists=yes
array_all_key exists=yes
any_key_y=T
all_key=T
any_empty=F
all_empty=T
any_key_empty=F
all_key_empty=T
any=T
all=T

--TEST--
stdlib array_first_key()/array_last_key() phantom on PHP 8.4 forward profile (#22793, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

foreach (['array_first_key', 'array_last_key'] as $fn) {
    echo $fn, ' exists=', function_exists($fn) ? 'yes' : 'no', "\n";
}
foreach (['array_key_first', 'array_key_last'] as $fn) {
    echo $fn, ' exists=', function_exists($fn) ? 'yes' : 'no', "\n";
}
foreach (['array_first', 'array_last'] as $fn) {
    echo $fn, ' exists=', function_exists($fn) ? 'yes' : 'no', "\n";
}

$a = ['x' => 1, 'y' => 2];
echo array_key_first($a), "\n";
echo array_key_last($a), "\n";
--EXPECT--
array_first_key exists=no
array_last_key exists=no
array_key_first exists=yes
array_key_last exists=yes
array_first exists=no
array_last exists=no
x
y

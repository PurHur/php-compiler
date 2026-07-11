--TEST--
stdlib extract() EXTR_PREFIX_ALL prefixes every imported key (php-src array.c)
--FILE--
<?php
$data = ['foo' => 1];
extract($data, EXTR_PREFIX_ALL, 'all');
echo $all_foo ?? 'undef', "\n";
--EXPECT--
1

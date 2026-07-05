--TEST--
stdlib ini_set() date.timezone — reordered named params JIT (#16624)
--FILE--
<?php
ini_set(value: 'Europe/London', option: 'date.timezone');
echo ini_get('date.timezone'), "\n";
--EXPECT--
Europe/London

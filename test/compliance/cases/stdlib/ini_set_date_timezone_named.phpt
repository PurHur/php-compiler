--TEST--
stdlib ini_set() date.timezone — reordered value:/option: named params (#16624, ext/standard/ini.c)
--FILE--
<?php
ini_set(value: 'Europe/London', option: 'date.timezone');
echo ini_get('date.timezone'), "\n";
--EXPECT--
Europe/London

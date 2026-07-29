--TEST--
stdlib intval() must not recognize 0o octal prefix — Zend strtol parity (#24729)
--FILE--
<?php
var_dump(intval("0o17", 8));
var_dump(intval("0o17", 0));
var_dump(intval("017", 8));
var_dump(intval("017", 0));
var_dump(intval("0x1A", 16));
var_dump(intval("0x1A", 0));
var_dump(intval("0b1010", 2));
var_dump(intval("0b1010", 0));
var_dump(intval("-17", 8));
--EXPECT--
int(0)
int(0)
int(15)
int(15)
int(26)
int(26)
int(10)
int(10)
int(-15)

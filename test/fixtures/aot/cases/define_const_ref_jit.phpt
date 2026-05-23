--TEST--
AOT: define() JIT with const reference value (issue #1118)
--FILE--
<?php
const V = 7;
define('BOOT_CONST', V);
echo defined('BOOT_CONST') ? (string) BOOT_CONST : '0';
--EXPECT--
7

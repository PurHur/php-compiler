--TEST--
AOT: key_exists() alias registers and matches array_key_exists() (#5850)
--FILE--
<?php
echo function_exists('key_exists') ? "1" : "0", "\n";
echo key_exists(1, [1 => 'x']) ? "1" : "0", "\n";
--EXPECT--
1
1

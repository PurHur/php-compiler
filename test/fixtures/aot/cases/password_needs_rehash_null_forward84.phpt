--TEST--
AOT: password_needs_rehash(null) soft-null on 8.4 — no TypeError abort (#21314)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Soft-null: must not abort with TypeError. Boolean may differ from VM for empty hash (AOT gap).
$r = password_needs_rehash(null, PASSWORD_DEFAULT);
echo is_bool($r) ? "bool\n" : "bad\n";
?>
--EXPECT--
bool

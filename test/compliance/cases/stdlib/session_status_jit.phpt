--TEST--
stdlib session_status() JIT with SessionStatus enum (#7321)
--FILE--
<?php
ob_start();
$st = session_status();
if (is_object($st)) {
    echo $st->value, "\n";
    echo $st->value === PHP_SESSION_NONE ? "none\n" : "bad\n";
} else {
    echo $st, "\n";
    echo $st === PHP_SESSION_NONE ? "none\n" : "bad\n";
}
session_start();
$st = session_status();
if (is_object($st)) {
    echo $st->value, "\n";
    echo $st->value === PHP_SESSION_ACTIVE ? "active\n" : "bad\n";
} else {
    echo $st, "\n";
    echo $st === PHP_SESSION_ACTIVE ? "active\n" : "bad\n";
}
--EXPECT--
1
none
2
active

--TEST--
stdlib session_unset() clears $_SESSION but keeps session active (#6261, ext/session/session.c)
--FILE--
<?php
session_start();
$_SESSION['x'] = 1;
$before = session_status();
session_unset();
$after = session_status();
if (enum_exists('SessionStatus')) {
    echo ($before === PHP_SESSION_ACTIVE && $after === PHP_SESSION_ACTIVE) ? "active\n" : "inactive\n";
} else {
    echo ($before === PHP_SESSION_ACTIVE && $after === PHP_SESSION_ACTIVE) ? "active\n" : "inactive\n";
}
echo empty($_SESSION) ? "empty\n" : "notempty\n";
--EXPECT--
active
empty

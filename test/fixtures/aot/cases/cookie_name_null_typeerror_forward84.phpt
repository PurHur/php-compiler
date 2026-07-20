--TEST--
AOT setcookie(null) — empty name ValueError on 8.4 forward profile (#21233, re-#21003)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    setcookie(null);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo "ValueError\n";
}
?>
--EXPECT--
ValueError

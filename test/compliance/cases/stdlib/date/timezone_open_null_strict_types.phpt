--TEST--
stdlib timezone_open(null) — TypeError under declare(strict_types=1) (#18888, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

try {
    timezone_open(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
timezone_open(): Argument #1 ($timezone) must be of type string, null given

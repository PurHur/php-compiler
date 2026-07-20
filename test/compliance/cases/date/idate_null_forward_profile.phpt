--TEST--
stdlib idate(null) — soft-null DEP+WARN+false on 8.4 forward profile (#21491, reverts #20227; ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    if (E_WARNING === $no) {
        echo "WARN\n";
        return true;
    }
    return false;
});
try {
    $r = idate(null);
    echo "OK ", var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
DEP
WARN
OK false

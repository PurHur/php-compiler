--TEST--
date strtotime(null) — soft-null DEP+false on 8.4 forward profile JIT (#21208, reverts #19651; ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
try {
    echo var_export(strtotime(null), true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
DEP
false

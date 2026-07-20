--TEST--
JIT PROFILE=8.4: number_format(null) soft-null DEP+0 (#21429)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
function check(): void
{
    error_reporting(E_ALL);
    set_error_handler(static function (int $no, string $str): bool {
        if (E_DEPRECATED === $no) {
            echo "DEP\n";
            return true;
        }
        return false;
    });
    try {
        echo var_export(number_format(null), true), "\n";
    } catch (\Throwable $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
}
check();
?>
--EXPECT--
DEP
'0'

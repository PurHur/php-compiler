--TEST--
JIT define()/defined()/constant(null) soft-null on 8.4 (#21281, re-#19652)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
echo 'define=', var_export(define(null, 1), true), "\n";
echo 'defined=', var_export(defined(null), true), "\n";
try {
    echo 'constant=', var_export(constant(null), true), "\n";
} catch (Error $e) {
    echo 'constant Error: ', $e->getMessage(), "\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 3), "\n";
?>
--EXPECT--
define=true
defined=true
constant=1
depr=1

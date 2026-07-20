--TEST--
json json_decode(null) — E_DEPRECATED + NULL on 8.4 forward profile JIT (#21223)
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
try {
    $r = json_decode(null);
    echo var_export($r, true), "\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
?>
--EXPECT--
NULL
depr=1

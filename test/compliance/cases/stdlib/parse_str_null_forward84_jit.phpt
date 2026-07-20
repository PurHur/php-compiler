--TEST--
stdlib parse_str(null) — TypeError on 8.4 forward profile JIT (#21380, re-#20113)
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
    parse_str(null, $o);
    echo var_export($o, true), "\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
parse_str('', $empty);
echo var_export($empty, true), "\n";
?>
--EXPECT--
TypeError
depr=0
array (
)

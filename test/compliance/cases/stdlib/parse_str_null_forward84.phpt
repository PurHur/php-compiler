--TEST--
stdlib parse_str(null) — soft-null DEP+coerce on 8.4 (#21480, reverts #21380)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
array (
)
depr=1
array (
)

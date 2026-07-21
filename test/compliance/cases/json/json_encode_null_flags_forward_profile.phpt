--TEST--
json json_encode(val, null) — E_DEPRECATED + encode on 8.4 forward profile (#21722)
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
$r = json_encode("x", null);
echo var_export($r, true), "\n";
restore_error_handler();
echo 'depr=', count($seen), "\n";
echo 'msg=', $seen[0] ?? '(none)', "\n";
?>
--EXPECT--
'"x"'
depr=1
msg=json_encode(): Passing null to parameter #2 ($flags) of type int is deprecated

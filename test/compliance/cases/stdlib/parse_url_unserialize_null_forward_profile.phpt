--TEST--
stdlib unserialize(null) — E_DEPRECATED + false on 8.4 forward profile (#21223, reverts #19222)
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
    $r = unserialize(null);
    echo var_export($r, true), "\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 1), "\n";
?>
--EXPECT--
false
depr=1

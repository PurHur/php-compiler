--TEST--
mbstring mb_str_pad() null $string — DEP+coerce on 8.4 JIT/AOT (#24176, reverts #19184/#22373)
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
$ok = true;
try {
    if ('     ' !== mb_str_pad(null, 5)) {
        $ok = false;
    }
} catch (TypeError $e) {
    $ok = false;
}
restore_error_handler();
echo $ok && count($seen) >= 1 ? "ok\n" : "bad\n";
?>
--EXPECT--
ok

--TEST--
mbstring mb_str_split() null $string — DEP+coerce on 8.4 JIT (#24207, peer #24176)
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
    $r = mb_str_split(null);
    if (!\is_array($r) || [] !== $r) {
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

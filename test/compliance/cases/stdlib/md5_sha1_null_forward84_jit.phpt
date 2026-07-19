--TEST--
stdlib md5()/sha1() null $string — E_DEPRECATED + empty digest on 8.4 JIT (#21181)
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
foreach (['md5', 'sha1'] as $fn) {
    try {
        $r = $fn(null);
        echo "{$fn}: ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "{$fn}: TypeError\n";
    }
}
restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
echo var_export(md5(''), true), "\n";
?>
--EXPECT--
md5: 'd41d8cd98f00b204e9800998ecf8427e'
sha1: 'da39a3ee5e6b4b0d3255bfef95601890afd80709'
depr=1
'd41d8cd98f00b204e9800998ecf8427e'

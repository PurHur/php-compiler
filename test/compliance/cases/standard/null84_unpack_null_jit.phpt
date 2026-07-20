--TEST--
stdlib unpack() null $string soft-null on 8.4 — JIT (#21246); format soft-null (#21478)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$dep = 0;
$warn = 0;
set_error_handler(static function (int $no) use (&$dep, &$warn): bool {
    if (E_DEPRECATED === $no) {
        ++$dep;
        return true;
    }
    if (E_WARNING === $no) {
        ++$warn;
        return true;
    }
    return true;
});
$lines = [];
try {
    $r = unpack('C', null);
    $lines[] = 'C_null: ' . var_export($r, true);
} catch (TypeError $e) {
    $lines[] = $e->getMessage();
}
try {
    $r = unpack('a*', null);
    $lines[] = 'a_null: ' . json_encode($r);
} catch (TypeError $e) {
    $lines[] = $e->getMessage();
}
$fmtLabel = 'fmt COERCED';
try {
    unpack(null, 'x');
} catch (TypeError $e) {
    $fmtLabel = 'fmt TypeError';
}
$lines[] = $fmtLabel;
restore_error_handler();
echo implode("\n", $lines), "\n";
echo 'dep=', (int) ($dep >= 1), ' warn=', (int) ($warn >= 1), "\n";
?>
--EXPECT--
C_null: false
a_null: {"1":""}
fmt COERCED
dep=1 warn=1

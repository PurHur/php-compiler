--TEST--
stdlib printf()/fprintf() null format DEP+coerce on 8.4 — JIT (#21234, reverts #20197)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        ++$deps;
    }
    return true;
});
try {
    var_export(printf(null));
    echo ($deps >= 1 ? ' DEP' : ''), " printf COERCE\n";
} catch (TypeError $e) {
    echo "printf TypeError\n";
}
$fp = fopen('php://memory', 'w+');
$prev = $deps;
try {
    var_export(fprintf($fp, null));
    echo ($deps > $prev ? ' DEP' : ''), " fprintf COERCE\n";
} catch (TypeError $e) {
    echo "fprintf TypeError\n";
}
fclose($fp);
--EXPECT--
0 DEP printf COERCE
0 DEP fprintf COERCE

--TEST--
stdlib printf()/fprintf() null format TypeError on 8.4 — JIT (#20197)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    var_export(printf(null));
    echo " printf COERCE\n";
} catch (TypeError $e) {
    echo "printf TypeError\n";
}
$fp = fopen('php://memory', 'w+');
try {
    var_export(fprintf($fp, null));
    echo " fprintf COERCE\n";
} catch (TypeError $e) {
    echo "fprintf TypeError\n";
}
fclose($fp);
--EXPECT--
printf TypeError
fprintf TypeError

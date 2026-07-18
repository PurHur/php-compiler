--TEST--
stdlib uniqid(null) TypeError on 8.4 — JIT (#20138)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    var_export(uniqid(null));
    echo " COERCE\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
TypeError

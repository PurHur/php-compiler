--TEST--
stdlib function_exists()/method_exists()/property_exists(null) TypeError on 8.4 JIT (#20360)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    function_exists(null);
    echo "fail function_exists\n";
} catch (TypeError $e) {
    echo "ok function_exists\n";
}
try {
    method_exists('stdClass', null);
    echo "fail method_exists\n";
} catch (TypeError $e) {
    echo "ok method_exists\n";
}
try {
    property_exists(new stdClass, null);
    echo "fail property_exists\n";
} catch (TypeError $e) {
    echo "ok property_exists\n";
}
try {
    class_exists(null);
    echo "fail class_exists\n";
} catch (TypeError $e) {
    echo "ok class_exists\n";
}
--EXPECT--
ok function_exists
ok method_exists
ok property_exists
ok class_exists

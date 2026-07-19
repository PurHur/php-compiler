--TEST--
JIT: stdlib trigger_error()/user_error(null) TypeError on 8.4 (#21035, Zend/zend_builtin_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['trigger_error', 'user_error'] as $f) {
    try {
        $r = $f(null);
        echo $f, ' COERCED ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $f, " TypeError\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
--EXPECT--
trigger_error TypeError
user_error TypeError

--TEST--
JIT: stdlib trigger_error()/user_error(null) soft-null on 8.4 (#21480, reverts #21035)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (): bool {
    return true;
});
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
trigger_error COERCED true
user_error COERCED true

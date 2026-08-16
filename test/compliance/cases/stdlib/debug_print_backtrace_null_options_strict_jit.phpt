--TEST--
stdlib debug_print_backtrace(null $options) TypeError under strict_types JIT (#31384, ext/standard/basic_functions.stub.php)
--FILE--
<?php
declare(strict_types=1);
try {
    debug_print_backtrace(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
ob_start();
debug_print_backtrace();
ob_end_clean();
echo "ok\n";
--EXPECT--
debug_print_backtrace(): Argument #1 ($options) must be of type int, null given
ok

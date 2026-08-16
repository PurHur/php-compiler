--TEST--
stdlib debug_print_backtrace(null $options) soft DEP+coerce outside strict_types (#31384, ext/standard/basic_functions.stub.php)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    ob_start();
    debug_print_backtrace(null);
    ob_end_clean();
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: debug_print_backtrace(): Passing null to parameter #1 ($options) of type int is deprecated in %s on line %d
ok

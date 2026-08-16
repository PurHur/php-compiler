--TEST--
stdlib print_r/var_export(null $return) soft DEP+coerce outside strict_types (#31337, ext/standard/var.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo print_r([1], null) ? "ok\n" : "bad\n";
    var_export(1, null);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: print_r(): Passing null to parameter #2 ($return) of type bool is deprecated in %s on line %d
Array
(
    [0] => 1
)
ok
%ADeprecated: var_export(): Passing null to parameter #2 ($return) of type bool is deprecated in %s on line %d
1

--TEST--
mbstring mb_split(null $limit) soft DEP+coerce outside strict_types (#31312, php_mbregex.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo var_export(mb_split('X', 'aXbXcXd', null), true), "\n";
    echo var_export(mb_split('X', 'aXbXcXd', 0), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: mb_split(): Passing null to parameter #3 ($limit) of type int is deprecated in %s on line %d
array (
  0 => 'aXbXcXd',
)
array (
  0 => 'aXbXcXd',
)

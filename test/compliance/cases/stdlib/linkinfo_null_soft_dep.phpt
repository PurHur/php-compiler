--TEST--
stdlib linkinfo(null) soft DEP+warn+-1 outside strict_types (#31262, ext/standard/link.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $r = linkinfo(null);
    echo var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: linkinfo(): Passing null to parameter #1 ($path) of type string is deprecated in %s on line %d
%AWarning: linkinfo(): No such file or directory in %s on line %d
-1

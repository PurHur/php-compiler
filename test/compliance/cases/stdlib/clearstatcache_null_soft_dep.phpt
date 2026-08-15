--TEST--
stdlib clearstatcache(null) soft DEP+coerce outside strict_types (#31245, ext/standard/filestat.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $r = clearstatcache(null);
    echo null === $r ? "null\n" : var_export($r, true) . "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: clearstatcache(): Passing null to parameter #1 ($clear_realpath_cache) of type bool is deprecated in %s on line %d
null

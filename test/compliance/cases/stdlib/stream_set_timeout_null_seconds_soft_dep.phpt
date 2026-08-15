--TEST--
stdlib stream_set_timeout(null $seconds) soft DEP+coerce outside strict_types (#31263, ext/standard/streamsfuncs.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $r = stream_set_timeout(STDIN, null);
    echo var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: stream_set_timeout(): Passing null to parameter #2 ($seconds) of type int is deprecated in %s on line %d
false

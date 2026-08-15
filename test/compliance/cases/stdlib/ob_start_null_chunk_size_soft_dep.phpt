--TEST--
stdlib ob_start(null, null) soft DEP+coerce outside strict_types (#31228, ext/standard/output.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    $r = ob_start(null, null);
    $level = ob_get_level();
    if ($r) {
        ob_end_clean();
    }
    echo var_export($r, true), ' level=', $level, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
%ADeprecated: ob_start(): Passing null to parameter #2 ($chunk_size) of type int is deprecated in %s on line %d
true level=1

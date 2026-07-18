<?php
// Repro #20113: parse_str(null) must TypeError under PHP_COMPILER_PROFILE=8.4.
try {
    parse_str(null, $o);
    var_export($o);
    echo " parse_str\n";
} catch (Throwable $e) {
    echo get_class($e), " parse_str\n";
}
try {
    var_export(md5(null));
    echo " md5\n";
} catch (Throwable $e) {
    echo get_class($e), " md5\n";
}

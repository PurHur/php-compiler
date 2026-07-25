<?php
/** Repro #23156 — get_extension_funcs ownership vs Zend (ftp/random populated; phar/ffi false). */
foreach (['ftp', 'intl', 'random', 'pgsql', 'phar', 'ffi'] as $e) {
    $f = get_extension_funcs($e);
    if ($f === false) {
        echo "$e\tfalse\n";
    } elseif (is_array($f)) {
        echo "$e\tarray(".count($f).")\n";
    } else {
        echo "$e\t".gettype($f)."\n";
    }
}

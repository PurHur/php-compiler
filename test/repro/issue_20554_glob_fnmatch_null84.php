<?php
// Repro #20554 — glob()/fnmatch() null pattern under PHP_COMPILER_PROFILE=8.4.

foreach (['glob' => fn () => glob(null), 'fnmatch' => fn () => fnmatch(null, 'a')] as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ' COERCED ', var_export($r, true), PHP_EOL;
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), PHP_EOL;
    }
}

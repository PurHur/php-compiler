<?php
// Repro #19433 — mb_ucfirst/mb_lcfirst(null) must TypeError under PHP_COMPILER_PROFILE=8.4
foreach (['mb_ucfirst', 'mb_lcfirst'] as $f) {
    try {
        var_export($f(null));
        echo " $f:OK\n";
    } catch (Throwable $e) {
        echo "$f: ", get_class($e), "\n";
    }
}
echo mb_ucfirst('über'), "\n";

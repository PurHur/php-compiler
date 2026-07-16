<?php
/** Repro #19432 — stripcslashes(null) TypeError on PHP_COMPILER_PROFILE=8.4. */
try {
    var_export(stripcslashes(null));
    echo "\nstripcslashes: uncaught\n";
} catch (TypeError $e) {
    echo 'stripcslashes: '.$e->getMessage()."\n";
}
echo "ok\n";

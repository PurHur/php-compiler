<?php
/**
 * Repro #18980: substr(null) — TypeError on 8.4 forward profile (ext/standard/string.c).
 */
try {
    $r = substr(null, 0);
    echo 'uncaught: '.var_export($r, true)."\n";
} catch (TypeError $e) {
    echo $e->getMessage()."\n";
}

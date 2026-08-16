<?php
/** Repro for #30238 / #24821 — array_find family phantom on default (Zend 8.2) profile. */
foreach (['array_find', 'array_find_key', 'array_any', 'array_all'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}

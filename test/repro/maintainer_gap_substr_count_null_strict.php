<?php
declare(strict_types=1);
// Repro for #29808: substr_count(null, "a") must throw TypeError under strict_types.
try {
    var_export(substr_count(null, 'a'));
    echo "bad: coerced\n";
} catch (\TypeError $e) {
    echo "ok: TypeError: " . $e->getMessage() . "\n";
}

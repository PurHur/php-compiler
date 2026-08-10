<?php
// Issue #29817 — is_a/is_subclass_of(..., null) under strict_types must TypeError on $class.
// Soft (non-strict) path coerces null→"" then returns false — not covered here.

declare(strict_types=1);

try {
    var_export(is_a('X', null));
    echo "\nfail:is_a_string:no_throw\n";
} catch (TypeError $e) {
    echo 'ok:is_a_string:', $e->getMessage(), "\n";
}

try {
    var_export(is_a(new stdClass(), null));
    echo "\nfail:is_a_object:no_throw\n";
} catch (TypeError $e) {
    echo 'ok:is_a_object:', $e->getMessage(), "\n";
}

try {
    var_export(is_subclass_of('X', null));
    echo "\nfail:is_subclass_of:no_throw\n";
} catch (TypeError $e) {
    echo 'ok:is_subclass_of:', $e->getMessage(), "\n";
}

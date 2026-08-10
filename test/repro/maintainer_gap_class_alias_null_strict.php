<?php
// Issue #29816 — class_alias(null) under strict_types must TypeError (Zend Z_PARAM_STR).
// Soft (non-strict) path remains DEP+coerce — see ClassAliasNullDep29661Test.

declare(strict_types=1);

try {
    var_export(class_alias(null, 'X'));
    echo "\nfail:class_alias:no_throw\n";
} catch (TypeError $e) {
    echo 'ok:class_alias:', $e->getMessage(), "\n";
}

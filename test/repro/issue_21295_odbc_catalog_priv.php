<?php
// Issue #21295 — odbc tableprivileges/columnprivileges registration.
foreach (['odbc_tableprivileges', 'odbc_columnprivileges'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

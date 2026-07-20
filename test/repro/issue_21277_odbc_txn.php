<?php
// Issue #21277 — odbc_autocommit / odbc_commit / odbc_rollback registration.
foreach (['odbc_autocommit', 'odbc_commit', 'odbc_rollback'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

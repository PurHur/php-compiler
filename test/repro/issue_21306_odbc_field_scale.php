<?php
// Issue #21306 — odbc_field_scale / odbc_field_precision registration.
foreach (['odbc_field_scale', 'odbc_field_precision'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

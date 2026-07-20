<?php
// Issue #21308 — odbc_do alias + odbc_result_all registration.
foreach (['odbc_do', 'odbc_result_all'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

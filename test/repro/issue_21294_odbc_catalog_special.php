<?php
// Issue #21294 — odbc specialcolumns/procedures/procedurecolumns registration.
foreach (['odbc_specialcolumns', 'odbc_procedures', 'odbc_procedurecolumns'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

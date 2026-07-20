<?php
// Issue #21279 — odbc catalog API registration.
foreach (['odbc_primarykeys', 'odbc_foreignkeys', 'odbc_statistics', 'odbc_gettypeinfo'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

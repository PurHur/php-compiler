<?php
// Issue #21267 remnant — odbc_setoption (txn/binmode already shipped).
echo 'odbc_setoption=', function_exists('odbc_setoption') ? 'yes' : 'MISSING', "\n";
foreach (['odbc_autocommit', 'odbc_commit', 'odbc_rollback', 'odbc_binmode', 'odbc_longreadlen', 'odbc_setoption'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}

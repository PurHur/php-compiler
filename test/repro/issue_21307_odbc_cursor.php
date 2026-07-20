<?php
// Issue #21307 — odbc_cursor registration.
echo 'odbc_cursor=', function_exists('odbc_cursor') ? 'yes' : 'MISSING', "\n";

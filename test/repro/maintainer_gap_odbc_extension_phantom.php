<?php

declare(strict_types=1);

// Maintainer repro: #23969 — odbc phantom on default profile (Zend without ext/odbc).
echo 'ext=', extension_loaded('odbc') ? '1' : '0', "\n";
echo 'fn=', function_exists('odbc_connect') ? '1' : '0', "\n";

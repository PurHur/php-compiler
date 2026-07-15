<?php
declare(strict_types=1);

// Issue #6565 — ftp_ssl_connect() registration + refused connect returns false.
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_ftp_ssl_connect.php

var_export(function_exists('ftp_ssl_connect'));
echo "\n";
$conn = @ftp_ssl_connect('127.0.0.1', 990, 1);
var_export($conn);
echo "\n";

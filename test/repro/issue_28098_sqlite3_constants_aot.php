<?php
declare(strict_types=1);

/**
 * AOT-safe repro for #28098 — class constants only (no setAuthorizer NestedJIT).
 * Run: PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_28098_sqlite3_constants_aot.php
 */
echo 'OK=', (int) SQLite3::OK, "\n";
echo 'DENY=', (int) SQLite3::DENY, "\n";
echo 'IGNORE=', (int) SQLite3::IGNORE, "\n";
echo 'CREATE_TABLE=', (int) SQLite3::CREATE_TABLE, "\n";
echo 'defined_OK=', defined('SQLite3::OK') ? 'yes' : 'no', "\n";
echo 'constant_OK=', (int) constant('SQLite3::OK'), "\n";

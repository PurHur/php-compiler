<?php

declare(strict_types=1);

$beforeLoaded = php_ini_loaded_file();
$beforeScanned = php_ini_scanned_files();

putenv('PHP_COMPILER_INI_LOADED_FILE=/etc/custom/php.ini');
putenv('PHP_COMPILER_INI_SCANNED_FILES=/etc/custom/a.ini,
/etc/custom/b.ini,
');

if (getenv('PHP_COMPILER_INI_LOADED_FILE') !== '/etc/custom/php.ini') {
    echo "fail: getenv loaded override\n";
    exit(1);
}

if (php_ini_loaded_file() === '/etc/custom/php.ini') {
    echo "fail: php_ini_loaded_file spoofed by putenv\n";
    exit(1);
}

if ($beforeLoaded !== false && php_ini_loaded_file() !== $beforeLoaded) {
    echo "fail: php_ini_loaded_file changed after putenv\n";
    exit(1);
}

$scanned = php_ini_scanned_files();
if (is_string($scanned) && str_contains($scanned, '/etc/custom/a.ini,')) {
    echo "fail: php_ini_scanned_files spoofed by putenv\n";
    exit(1);
}

if ($beforeScanned !== false && $scanned !== $beforeScanned) {
    echo "fail: php_ini_scanned_files changed after putenv\n";
    exit(1);
}

echo "ok\n";

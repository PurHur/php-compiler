<?php

declare(strict_types=1);

putenv('PHP_COMPILER_INI_LOADED_FILE=/etc/custom/php.ini');
putenv('PHP_COMPILER_INI_SCANNED_FILES=/etc/custom/a.ini,
/etc/custom/b.ini,
');

if (getenv('PHP_COMPILER_INI_LOADED_FILE') !== '/etc/custom/php.ini') {
    echo "fail: getenv loaded override\n";
    exit(1);
}

if (php_ini_loaded_file() !== '/etc/custom/php.ini') {
    echo 'fail: php_ini_loaded_file expected /etc/custom/php.ini got ';
    var_export(php_ini_loaded_file());
    echo "\n";
    exit(1);
}

$scanned = php_ini_scanned_files();
if (!is_string($scanned) || !str_contains($scanned, '/etc/custom/a.ini,')) {
    echo "fail: php_ini_scanned_files override\n";
    exit(1);
}

echo "ok\n";

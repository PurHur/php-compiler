--TEST--
stdlib php_ini_loaded_file() / php_ini_scanned_files() — registered; putenv cannot spoof (#6117, #9175, #15111)
--FILE--
<?php
echo function_exists('php_ini_loaded_file') ? "loaded_fn\n" : "missing_loaded\n";
echo function_exists('php_ini_scanned_files') ? "scanned_fn\n" : "missing_scanned\n";
$loaded = php_ini_loaded_file();
echo is_string($loaded) && '' !== $loaded ? "loaded_path\n" : (false === $loaded ? "loaded_false\n" : "loaded_bad\n");
$scanned = php_ini_scanned_files();
echo is_string($scanned) && '' !== $scanned ? "scanned_path\n" : (false === $scanned ? "scanned_false\n" : "scanned_bad\n");
$beforeLoaded = php_ini_loaded_file();
$beforeScanned = php_ini_scanned_files();
putenv('PHP_COMPILER_INI_LOADED_FILE=/etc/custom/php.ini');
putenv('PHP_COMPILER_INI_SCANNED_FILES=/etc/custom/a.ini,
/etc/custom/b.ini,
');
echo php_ini_loaded_file() === '/etc/custom/php.ini' ? "loaded_override_bad\n" : "loaded_no_override\n";
$scanned = php_ini_scanned_files();
echo is_string($scanned) && str_contains($scanned, '/etc/custom/a.ini,') ? "scanned_override_bad\n" : "scanned_no_override\n";
echo php_ini_loaded_file() === $beforeLoaded ? "loaded_stable\n" : "loaded_changed_bad\n";
echo php_ini_scanned_files() === $beforeScanned ? "scanned_stable\n" : "scanned_changed_bad\n";
--EXPECT--
loaded_fn
scanned_fn
loaded_path
scanned_path
loaded_no_override
scanned_no_override
loaded_stable
scanned_stable

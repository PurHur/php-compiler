--TEST--
stdlib php_ini_loaded_file() / php_ini_scanned_files() — registered and env-driven (#6117)
--FILE--
<?php
echo function_exists('php_ini_loaded_file') ? "loaded_fn\n" : "missing_loaded\n";
echo function_exists('php_ini_scanned_files') ? "scanned_fn\n" : "missing_scanned\n";
echo php_ini_loaded_file() === false ? "loaded_false\n" : "loaded_bad\n";
echo php_ini_scanned_files() === false ? "scanned_false\n" : "scanned_bad\n";
putenv('PHP_COMPILER_INI_LOADED_FILE=/etc/custom/php.ini');
putenv('PHP_COMPILER_INI_SCANNED_FILES=/etc/custom/a.ini,
/etc/custom/b.ini,
');
echo php_ini_loaded_file() === '/etc/custom/php.ini' ? "loaded_path\n" : "loaded_path_bad\n";
$scanned = php_ini_scanned_files();
echo str_contains($scanned, '/etc/custom/a.ini,') ? "scanned_path\n" : "scanned_path_bad\n";
--EXPECT--
loaded_fn
scanned_fn
loaded_false
scanned_false
loaded_path
scanned_path

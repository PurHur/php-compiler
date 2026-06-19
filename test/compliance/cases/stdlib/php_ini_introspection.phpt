--TEST--
stdlib php_ini_loaded_file() / php_ini_scanned_files() — registered and env-driven (#6117, #9175)
--FILE--
<?php
echo function_exists('php_ini_loaded_file') ? "loaded_fn\n" : "missing_loaded\n";
echo function_exists('php_ini_scanned_files') ? "scanned_fn\n" : "missing_scanned\n";
$loaded = php_ini_loaded_file();
echo is_string($loaded) && '' !== $loaded ? "loaded_path\n" : (false === $loaded ? "loaded_false\n" : "loaded_bad\n");
$scanned = php_ini_scanned_files();
echo is_string($scanned) && '' !== $scanned ? "scanned_path\n" : (false === $scanned ? "scanned_false\n" : "scanned_bad\n");
putenv('PHP_COMPILER_INI_LOADED_FILE=/etc/custom/php.ini');
putenv('PHP_COMPILER_INI_SCANNED_FILES=/etc/custom/a.ini,
/etc/custom/b.ini,
');
echo php_ini_loaded_file() === '/etc/custom/php.ini' ? "loaded_override\n" : "loaded_override_bad\n";
$scanned = php_ini_scanned_files();
echo str_contains($scanned, '/etc/custom/a.ini,') ? "scanned_override\n" : "scanned_override_bad\n";
--EXPECT--
loaded_fn
scanned_fn
loaded_path
scanned_path
loaded_override
scanned_override

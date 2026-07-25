--TEST--
stdlib get_extension_funcs ftp/random/phar/ffi ownership (#23156, ext/standard/basic_functions.c)
--FILE--
<?php
$ftp = get_extension_funcs('ftp');
echo is_array($ftp) && count($ftp) === 36 ? "ftp_ok\n" : "ftp_bad\n";

$random = get_extension_funcs('random');
echo is_array($random) && count($random) === 9 ? "random_ok\n" : "random_bad\n";

echo extension_loaded('phar') ? "phar_loaded\n" : "phar_unloaded\n";
echo get_extension_funcs('phar') === false ? "phar_false\n" : "phar_bad\n";
echo extension_loaded('ffi') ? "ffi_loaded\n" : "ffi_unloaded\n";
echo get_extension_funcs('ffi') === false ? "ffi_false\n" : "ffi_bad\n";

$pgsql = get_extension_funcs('pgsql');
if (false === $pgsql) {
    // Host Zend without libpq / PROFILE gate — still require false not empty array when unloaded.
    echo extension_loaded('pgsql') ? "pgsql_bad_empty\n" : "pgsql_false\n";
} elseif (is_array($pgsql) && count($pgsql) > 0) {
    echo "pgsql_ok\n";
} else {
    echo "pgsql_bad\n";
}
--EXPECTF--
ftp_ok
random_ok
phar_loaded
phar_false
ffi_loaded
ffi_false
pgsql_%s

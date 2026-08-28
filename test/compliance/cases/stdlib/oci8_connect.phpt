--TEST--
ext/oci8 Phase 0 builtins registered; connect without OCI client raises catchable Error (#6441)
--SKIPIF--
<?php
if (!extension_loaded('oci8')) {
    die('skip ext/oci8 not loaded');
}
?>
--FILE--
<?php
foreach ([
    'oci_connect',
    'oci_parse',
    'oci_execute',
    'oci_fetch_array',
] as $fn) {
    echo $fn, '=', var_export(function_exists($fn), true), "\n";
}
echo 'extension_loaded=', var_export(extension_loaded('oci8'), true), "\n";

try {
    oci_connect('user', 'pass', 'localhost/XE');
    echo "connect=unexpected_success\n";
} catch (\Error $e) {
    echo 'connect_error=', get_class($e), "\n";
    echo 'connect_message=', $e->getMessage(), "\n";
}
?>
--EXPECT--
oci_connect=true
oci_parse=true
oci_execute=true
oci_fetch_array=true
extension_loaded=true
connect_error=Error
connect_message=oci_connect(): Oracle OCI8 extension requires Oracle Instant Client (libclntsh) — not available in this build

--TEST--
stdlib SODIUM_LIBRARY_VERSION / MAJOR / MINOR identity constants (#24069)
--SKIPIF--
<?php
if (!extension_loaded('sodium')) {
    die('skip ext/sodium not loaded on reference host');
}
if (!defined('SODIUM_LIBRARY_VERSION')) {
    die('skip SODIUM_LIBRARY_VERSION undefined on reference host');
}
?>
--FILE--
<?php
if (!extension_loaded('sodium') || !defined('SODIUM_LIBRARY_VERSION')) {
    echo "missing\n";
    exit(0);
}
echo 'version=', SODIUM_LIBRARY_VERSION, "\n";
echo 'major=', SODIUM_LIBRARY_MAJOR_VERSION, "\n";
echo 'minor=', SODIUM_LIBRARY_MINOR_VERSION, "\n";
echo 'type_v=', gettype(SODIUM_LIBRARY_VERSION), "\n";
echo 'type_maj=', gettype(SODIUM_LIBRARY_MAJOR_VERSION), "\n";
echo 'type_min=', gettype(SODIUM_LIBRARY_MINOR_VERSION), "\n";
--EXPECTF--
version=%s
major=%d
minor=%d
type_v=string
type_maj=integer
type_min=integer

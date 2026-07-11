<?php
// Compile-only (#11703): copy() directory source warning lowering.
error_reporting(E_ALL);
$dir = sys_get_temp_dir() . '/phpc_aot_copy_dir_compile';
if (!is_dir($dir)) {
    mkdir($dir);
}
$ok = copy($dir, $dir . '_dest');
var_dump($ok);

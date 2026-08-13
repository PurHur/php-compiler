<?php
/** Issue #30756 — parse_ini_file() JIT/AOT (ext/standard/basic_functions.c). */
$path = sys_get_temp_dir().'/phpc-30756.ini';
file_put_contents($path, "a=1\n");
$parsed = parse_ini_file($path);
echo $parsed['a'], "\n";
@unlink($path);
try {
    // Non-literal empty: literal '' aborts AOT compile via rejectEmpty (#29268).
    $empty = substr('x', 1);
    parse_ini_file($empty);
    echo "empty: miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
$missing = @parse_ini_file('/no/such/phpc-30756.ini');
echo false === $missing ? "false\n" : "not-false\n";

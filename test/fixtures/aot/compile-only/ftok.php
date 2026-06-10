<?php
// Compile-only (#6296): ftok() JIT/AOT lowering via __compiler_ftok.
$path = tempnam(sys_get_temp_dir(), 'ftok_aot');
$key = ftok($path, 't');
var_export(is_int($key));
@unlink($path);

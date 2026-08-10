<?php
// Repro #29660 — fnmatch(null, …) DEP must cite parameter #1 ($pattern) under PROFILE=8.4.
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    fwrite(STDERR, "ERR[$errno]: $errstr\n");

    return true;
});
var_export(fnmatch(null, 'a'));
echo "\n";

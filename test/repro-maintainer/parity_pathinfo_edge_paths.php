<?php

declare(strict_types=1);

// Issue #10277 — pathinfo() empty path and '.' vs Zend (ext/standard/string.c php_pathinfo).

var_export(pathinfo('', PATHINFO_DIRNAME));
echo "\n";
var_export(pathinfo('.', PATHINFO_FILENAME));
echo "\n";
var_export(array_keys(pathinfo('')));
echo "\n";

$cases = ['', '.', '..', '.cvsignore', 'file.tar.gz', '/path/noextension', '/path/emptyextension.'];
foreach ($cases as $path) {
    echo "=== {$path} ===\n";
    var_export(pathinfo($path));
    echo "\n";
}

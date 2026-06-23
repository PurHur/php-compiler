<?php
// Issue #10045 — file_get_contents() filename: named parameter (php-src ext/standard/file.c)
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
var_export(file_get_contents(filename: $path));
echo "\n";

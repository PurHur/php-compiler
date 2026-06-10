<?php
/** Maintainer repro for #3263 — parse_ini_string() / parse_ini_file(). */
var_export(function_exists('parse_ini_string'));
echo "\n";
var_export(function_exists('parse_ini_file'));
echo "\n";
var_export(parse_ini_string("a=1\nb=2"));
echo "\n";
$ini = <<<INI
[app]
name = "My App"
debug = on
port = 8080
INI;
var_export(parse_ini_string($ini, true));
echo "\n";
$path = tempnam(sys_get_temp_dir(), 'phpc-ini-');
file_put_contents($path, $ini);
var_export(parse_ini_file($path, true));
echo "\n";
@unlink($path);

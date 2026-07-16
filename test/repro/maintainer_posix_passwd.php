<?php
foreach (['posix_getpwuid', 'posix_getgrgid', 'posix_getpwnam', 'posix_getgrnam', 'posix_getuid'] as $f) {
    echo $f, '=', var_export(function_exists($f), true), "\n";
}
$pw = posix_getpwuid(posix_getuid());
var_export(is_array($pw) && isset($pw['name']) && is_string($pw['name']) && '' !== $pw['name']);
echo "\n";
$gr = posix_getgrgid(posix_getgid());
var_export(is_array($gr) && isset($gr['name'], $gr['members']) && is_array($gr['members']));
echo "\n";
var_export(is_array(posix_getpwnam($pw['name'])));
echo "\n";
var_export(is_array(posix_getgrnam($gr['name'])));
echo "\n";
var_export(posix_getpwuid(999999));
echo "\n";

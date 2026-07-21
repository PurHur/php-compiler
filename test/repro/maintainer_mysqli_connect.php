<?php
var_export(function_exists('mysqli_connect'));
echo "\n";
var_export(class_exists('mysqli'));
echo "\n";
$mysqli = @mysqli_connect('127.0.0.1', 'root', '', 'test', 3306);
if (false === $mysqli) {
    echo 'connect_fail:', mysqli_connect_errno(), ':', mysqli_connect_error(), "\n";
    exit(0);
}
mysqli_query($mysqli, 'SELECT 1 AS n');
$r = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT 1 AS n'));
var_export($r);
echo "\n";
mysqli_close($mysqli);

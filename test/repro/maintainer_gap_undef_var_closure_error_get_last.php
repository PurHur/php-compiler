<?php
error_reporting(E_ALL);
$fn = function () {
    echo $missing, "\n";
};
$fn();
$e = error_get_last();
echo 'last:', $e['line'] ?? 0, "\n";

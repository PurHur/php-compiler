<?php
$hasFlush = function_exists('ob_flush');
$hasClean = function_exists('ob_clean');
$hasList = function_exists('ob_list_handlers');
var_export([$hasFlush, $hasClean, $hasList]);
echo "\n";

ob_start();
echo 'a';
ob_start();
echo 'b';
ob_flush();
echo '|inner=', ob_get_contents(), '|level=', ob_get_level(), "|\n";
while (ob_get_level()) {
    ob_end_clean();
}

ob_start();
echo 'x';
ob_clean();
var_export(ob_get_contents());
echo ' level=', ob_get_level(), "\n";
var_export(ob_clean());
echo ' no-buffer: ';
var_export(ob_clean());
echo "\n";
ob_end_clean();

ob_start();
ob_start();
var_export(ob_list_handlers());
echo "\n";
while (ob_get_level()) {
    ob_end_clean();
}

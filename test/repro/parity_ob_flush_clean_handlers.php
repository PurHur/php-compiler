<?php
/**
 * Issue #3588 repro — ob_flush/ob_clean/ob_list_handlers parity probe.
 */
ob_start();
var_export([
    function_exists('ob_get_contents'),
    function_exists('ob_flush'),
    function_exists('ob_clean'),
    function_exists('ob_list_handlers'),
]);
echo "\n";

ob_start();
echo 'a';
ob_start();
echo 'b';
ob_flush();
echo ob_get_contents(), "\n";

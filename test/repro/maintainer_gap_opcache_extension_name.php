<?php
/**
 * Repro #24993 — extension_loaded opcache vs Zend OPcache (php-src zend_accelerator_module.c).
 */
var_export(extension_loaded('opcache'));
echo "\n";
var_export(extension_loaded('Zend OPcache'));
echo "\n";
var_export(function_exists('opcache_get_status'));
echo "\n";
var_export(false !== get_extension_funcs('Zend OPcache'));
echo "\n";
var_export(false === get_extension_funcs('opcache'));
echo "\n";

<?php
declare(strict_types=1);
/**
 * #25010 — ext/zip must not phantom under PROFILE=8.4 when host Zend lacks it.
 */
echo 'extension_loaded(zip)=', var_export(extension_loaded('zip'), true), "\n";
echo 'class_exists(ZipArchive)=', var_export(class_exists('ZipArchive'), true), "\n";
echo 'function_exists(zip_open)=', var_export(function_exists('zip_open'), true), "\n";

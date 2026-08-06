<?php
declare(strict_types=1);

/**
 * Repro for #28110 — ZipArchive class constants must resolve after #25929 case-sensitive keys.
 * Run: PHP_COMPILER_ENABLE_ZIP=1 PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28110_ziparchive_constants.php
 */
echo 'ext=', extension_loaded('zip') ? 'yes' : 'no', "\n";
echo 'class=', class_exists('ZipArchive') ? 'yes' : 'no', "\n";
echo 'defined_CREATE=', defined('ZipArchive::CREATE') ? 'yes' : 'no', "\n";
echo 'CREATE=', (int) ZipArchive::CREATE, "\n";
echo 'OVERWRITE=', (int) ZipArchive::OVERWRITE, "\n";
echo 'ER_OK=', (int) ZipArchive::ER_OK, "\n";
echo 'CM_DEFAULT=', (int) ZipArchive::CM_DEFAULT, "\n";
echo 'EM_AES_128=', (int) ZipArchive::EM_AES_128, "\n";
$path = sys_get_temp_dir() . '/phpc_zip_28110_' . getmypid() . '.zip';
@unlink($path);
$z = new ZipArchive();
echo 'open=', var_export($z->open($path, ZipArchive::CREATE), true), "\n";
$z->close();
@unlink($path);

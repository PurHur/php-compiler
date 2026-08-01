<?php

declare(strict_types=1);

/**
 * Issue #6237 — RarArchive class_exists + open/list/extract on store fixture.
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_6237_rar_archive.php
 */

$fixture = dirname(__DIR__).'/fixtures/rar/tiny.rar';

echo class_exists('RarArchive') ? '1' : '0';
echo class_exists('RarException') ? '1' : '0';
echo extension_loaded('rar') ? '1' : '0';
echo "\n";

if (!class_exists('RarArchive')) {
    fwrite(STDERR, "RarArchive missing (set PHP_COMPILER_PROFILE=8.4 or PHP_COMPILER_ENABLE_RAR=1)\n");
    exit(1);
}

$arch = RarArchive::open($fixture);
$entries = $arch->getEntries();
echo $entries[0]->getName(), "\n";
$dir = sys_get_temp_dir().'/issue6237-'.getmypid();
@mkdir($dir);
$entries[0]->extract($dir);
echo file_get_contents($dir.'/hello.txt');

try {
    RarArchive::open('/no/such/rar-'.getmypid().'.rar');
    echo "unexpected\n";
} catch (RarException $e) {
    echo "ex\n";
}

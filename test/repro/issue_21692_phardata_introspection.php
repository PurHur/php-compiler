<?php
/**
 * Issue #21692 — PharData inherited introspection matches Zend on tar archives.
 */
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21692_repro_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$path = $dir . '/pint.tar';
@unlink($path);

$p = new PharData($path);
$p['a.txt'] = 'hi';
echo $p->isFileFormat(Phar::TAR) ? 'tar' : 'nottar', "\n";
echo $p->isFileFormat(Phar::ZIP) ? 'zip' : 'notzip', "\n";
echo $p->getModified() ? 'mod' : 'clean', "\n";
echo $p->count(), "\n";
echo $p->isWritable() ? 'y' : 'n', "\n";
echo $p->isCompressed() ? 'y' : 'n', "\n";

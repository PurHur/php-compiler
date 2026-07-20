<?php
/**
 * Issue #21230 — Phar::getVersion()/isWritable()/getModified().
 */
declare(strict_types=1);

foreach (['getVersion', 'isWritable', 'getModified'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21230_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
echo 'ver=', $phar->getVersion(), ' api=', Phar::apiVersion(), "\n";
echo 'writable=', $phar->isWritable() ? 'Y' : 'N', ' canWrite=', Phar::canWrite() ? 'Y' : 'N', "\n";
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
// Unflushed buffered writes: dirty (php-src is_modified intent).
echo 'buf_mod=', $phar->getModified() ? 'Y' : 'N', "\n";
$phar->stopBuffering();
echo 'flushed_mod=', $phar->getModified() ? 'Y' : 'N', "\n";
unset($phar);

$phar2 = new Phar($pharPath);
echo 'reopen_mod=', $phar2->getModified() ? 'Y' : 'N', "\n";
echo 'reopen_ver=', $phar2->getVersion(), "\n";

echo "ok\n";

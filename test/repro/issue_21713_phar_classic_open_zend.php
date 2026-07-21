<?php
declare(strict_types=1);
// Repro #21713 - open Zend-created classic PHAR (manifest + GBMB)
$p = __DIR__ . '/../fixtures/phar/zend_classic_hi.phar';
if (!is_file($p)) {
    fwrite(STDERR, "missing fixture\n");
    exit(1);
}
$phar = new Phar($p);
echo 'content=', $phar['a.txt']->getContent(), "\n";
$sig = $phar->getSignature();
echo 'sig_type=', is_array($sig) ? ($sig['hash_type'] ?? '') : 'false', "\n";
echo 'sig_hash=', is_array($sig) ? ($sig['hash'] ?? '') : '', "\n";
echo 'isPHAR=', $phar->isFileFormat(Phar::PHAR) ? '1' : '0', "\n";

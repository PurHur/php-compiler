--TEST--
ext/phar open Zend classic PHAR manifest + GBMB (#21713, ext/phar/phar.c)
--FILE--
<?php
declare(strict_types=1);
$p = __DIR__ . '/test/fixtures/phar/zend_classic_hi.phar';
if (!is_file($p)) {
    $p = dirname(__DIR__, 3) . '/fixtures/phar/zend_classic_hi.phar';
}
$phar = new Phar($p);
echo 'content=', $phar['a.txt']->getContent(), "\n";
$sig = $phar->getSignature();
echo 'sig=', is_array($sig) && ($sig['hash_type'] ?? '') === 'SHA-256' && ($sig['hash'] ?? '') === '04CD3B6A4ED8D06440081D47A5612BF40037AA5722779693C364BEB43E1E612B' ? 'ok' : 'fail', "\n";
echo 'isPHAR=', $phar->isFileFormat(Phar::PHAR) ? '1' : '0', "\n";
?>
--EXPECT--
content=hi
sig=ok
isPHAR=1

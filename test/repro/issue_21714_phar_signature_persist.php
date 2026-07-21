<?php
declare(strict_types=1);
// Repro #21714 - setSignatureAlgorithm persists GBMB across reopen
$dir = sys_get_temp_dir() . '/phar21714_' . getmypid();
@mkdir($dir, 0777, true);
$p = $dir . '/app.phar';
@unlink($p);
$halt = chr(95) . chr(95) . 'HALT_' . 'COMPILER';
$stub = '<?php ' . $halt . '();';
$phar = new Phar($p);
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
$phar->setStub($stub);
$phar->setSignatureAlgorithm(Phar::MD5);
$phar->stopBuffering();
$sig1 = $phar->getSignature();
echo 'create_type=', is_array($sig1) ? ($sig1['hash_type'] ?? '') : 'false', "\n";
$bin = file_get_contents($p);
echo 'gbmb=', (strlen($bin) >= 4 && substr($bin, -4) === 'GBMB') ? '1' : '0', "\n";
$phar2 = new Phar($p);
$sig2 = $phar2->getSignature();
echo 'reopen_type=', is_array($sig2) ? ($sig2['hash_type'] ?? '') : 'false', "\n";
echo 'reopen_match=', (is_array($sig1) && is_array($sig2) && ($sig1['hash'] ?? '') === ($sig2['hash'] ?? '') && ($sig2['hash_type'] ?? '') === 'MD5') ? 'ok' : 'fail', "\n";
$phar2->setSignatureAlgorithm(Phar::SHA1);
$phar3 = new Phar($p);
$sig3 = $phar3->getSignature();
echo 'sha1_reopen=', is_array($sig3) && ($sig3['hash_type'] ?? '') === 'SHA-1' && strlen($sig3['hash'] ?? '') === 40 ? 'ok' : 'fail', "\n";

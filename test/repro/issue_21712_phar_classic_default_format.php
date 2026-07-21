<?php
declare(strict_types=1);
// Repro #21712 - new Phar() classic FORMAT_PHAR + default SHA-256 + GBMB
$dir = sys_get_temp_dir() . '/phar21712_' . getmypid();
@mkdir($dir, 0777, true);
$p = $dir . '/app.phar';
@unlink($p);
$halt = chr(95) . chr(95) . 'HALT_' . 'COMPILER';
$stub = '<?php ' . $halt . '();';
$phar = new Phar($p);
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
$phar->setStub($stub);
$phar->stopBuffering();
echo 'isPHAR=', $phar->isFileFormat(Phar::PHAR) ? '1' : '0', "\n";
echo 'isTAR=', $phar->isFileFormat(Phar::TAR) ? '1' : '0', "\n";
$sig = $phar->getSignature();
echo 'sig_type=', is_array($sig) ? ($sig['hash_type'] ?? '') : 'false', "\n";
echo 'sig_len=', is_array($sig) ? strlen($sig['hash'] ?? '') : 0, "\n";
$bin = file_get_contents($p);
echo 'gbmb=', (strlen($bin) >= 4 && substr($bin, -4) === 'GBMB') ? '1' : '0', "\n";
$phar2 = new Phar($p);
echo 'reopen_content=', $phar2['a.txt']->getContent(), "\n";
$sig2 = $phar2->getSignature();
echo 'reopen_sig=', is_array($sig2) && ($sig2['hash_type'] ?? '') === 'SHA-256' ? 'ok' : 'fail', "\n";

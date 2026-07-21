--TEST--
ext/phar setSignatureAlgorithm GBMB persist + reopen (#21714, ext/phar/phar.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/phar21714c_' . getmypid();
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
$bin = file_get_contents($p);
echo 'gbmb=', (strlen($bin) >= 4 && substr($bin, -4) === 'GBMB') ? '1' : '0', "\n";
$phar2 = new Phar($p);
$sig2 = $phar2->getSignature();
echo 'md5_reopen=', is_array($sig2) && ($sig2['hash_type'] ?? '') === 'MD5' && ($sig1['hash'] ?? '') === ($sig2['hash'] ?? '') ? 'ok' : 'fail', "\n";
$phar2->setSignatureAlgorithm(Phar::SHA256);
$phar3 = new Phar($p);
$sig3 = $phar3->getSignature();
echo 'sha256_reopen=', is_array($sig3) && ($sig3['hash_type'] ?? '') === 'SHA-256' && strlen($sig3['hash'] ?? '') === 64 ? 'ok' : 'fail', "\n";
?>
--EXPECT--
gbmb=1
md5_reopen=ok
sha256_reopen=ok

--TEST--
ext/phar new Phar() classic FORMAT_PHAR + SHA-256 + GBMB (#21712, ext/phar/phar.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/phar21712c_' . getmypid();
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
echo 'sig=', is_array($sig) && ($sig['hash_type'] ?? '') === 'SHA-256' && strlen($sig['hash'] ?? '') === 64 ? 'ok' : 'fail', "\n";
$bin = file_get_contents($p);
echo 'gbmb=', (strlen($bin) >= 4 && substr($bin, -4) === 'GBMB') ? '1' : '0', "\n";
$re = new Phar($p);
echo 'reopen=', $re['a.txt']->getContent() === 'hi' && is_array($re->getSignature()) ? 'ok' : 'fail', "\n";
?>
--EXPECT--
isPHAR=1
isTAR=0
sig=ok
gbmb=1
reopen=ok

--TEST--
ext/phar Phar::setSignatureAlgorithm() + getSignature() hash roundtrip (#21329, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);
foreach (['Phar', 'PharData'] as $class) {
    echo $class, '_method=', method_exists($class, 'setSignatureAlgorithm') ? '1' : '0', "\n";
}
$dir = sys_get_temp_dir() . '/phar21329_' . getmypid();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('hello.txt', 'world');
$phar->setSignatureAlgorithm(Phar::MD5);
$phar->stopBuffering();
$sig = $phar->getSignature();
echo 'phar_sig=', is_array($sig) && ($sig['hash_type'] ?? '') === 'MD5' && strlen($sig['hash'] ?? '') === 32 ? 'ok' : 'fail', "\n";

$tarPath = $dir . '/data.tar';
$pd = new PharData($tarPath);
$pd->addFromString('a.txt', 'b');
$pd->setSignatureAlgorithm(Phar::SHA1);
$sig2 = $pd->getSignature();
echo 'data_sig=', is_array($sig2) && ($sig2['hash_type'] ?? '') === 'SHA-1' && strlen($sig2['hash'] ?? '') === 40 ? 'ok' : 'fail', "\n";
?>
--EXPECT--
Phar_method=1
PharData_method=1
phar_sig=ok
data_sig=ok

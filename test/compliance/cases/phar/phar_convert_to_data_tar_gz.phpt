--TEST--
stdlib Phar::convertToData(Phar::TAR, Phar::GZ) returns .tar.gz PharData (#21677, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phar_ctd_tgz_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$pharPath = $dir . '/app.phar';
if (is_file($pharPath)) {
    unlink($pharPath);
}
$p = new Phar($pharPath);
$p->addFromString('a.txt', 'hi');
$d = $p->convertToData(Phar::TAR, Phar::GZ);
echo 'class=', $d instanceof PharData ? 'PharData' : get_class($d), "\n";
$path = $d->getPath();
echo 'ext=', str_ends_with(strtolower($path), '.tar.gz') ? 'tar.gz' : 'other', "\n";
echo 'content=', $d['a.txt']->getContent(), "\n";
$bin = file_get_contents($path);
echo 'gz=', (is_string($bin) && strlen($bin) >= 2 && ord($bin[0]) === 0x1f && ord($bin[1]) === 0x8b) ? 'Y' : 'N', "\n";
$d2 = new PharData($path);
echo 'reopen=', $d2['a.txt']->getContent(), "\n";
?>
--EXPECT--
class=PharData
ext=tar.gz
content=hi
gz=Y
reopen=hi

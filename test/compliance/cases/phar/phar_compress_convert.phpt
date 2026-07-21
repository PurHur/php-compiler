--TEST--
stdlib Phar compress/decompress/convert/isCompressed/isFileFormat (#21328, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
foreach (['compress', 'decompress', 'convertToData', 'convertToExecutable', 'isCompressed', 'isFileFormat'] as $m) {
    echo $m, '=', method_exists('Phar', $m) ? '1' : '0', "\n";
}
$tmp = sys_get_temp_dir() . '/phar_compress_' . getmypid() . '.phar';
@unlink($tmp);
@unlink($tmp . '.gz');
@unlink(preg_replace('/\.phar$/', '.tar', $tmp));
$p = new Phar($tmp);
$p->addFromString('a.txt', 'hi');
echo 'fmt_phar=', $p->isFileFormat(Phar::PHAR) ? '1' : '0', "\n";
echo 'fmt_tar=', $p->isFileFormat(Phar::TAR) ? '1' : '0', "\n";
echo 'fmt_zip=', $p->isFileFormat(Phar::ZIP) ? '1' : '0', "\n";
echo 'comp0=', ($p->isCompressed() === false) ? 'F' : 'Y', "\n";
$gz = $p->compress(Phar::GZ);
echo 'gz_class=', $gz instanceof Phar ? 'Phar' : get_class($gz), "\n";
echo 'comp_gz=', ($gz->isCompressed() === Phar::GZ) ? 'GZ' : var_export($gz->isCompressed(), true), "\n";
echo 'content=', $gz['a.txt']->getContent(), "\n";
$plain = $gz->decompress();
echo 'plain_class=', $plain instanceof Phar ? 'Phar' : get_class($plain), "\n";
echo 'comp_plain=', ($plain->isCompressed() === false) ? 'F' : 'Y', "\n";
$data = $p->convertToData();
echo 'data_class=', $data instanceof PharData ? 'PharData' : get_class($data), "\n";
echo 'data_content=', $data['a.txt']->getContent(), "\n";
$exec = $p->convertToExecutable();
echo 'exec_class=', $exec instanceof Phar ? 'Phar' : get_class($exec), "\n";
@unlink($tmp);
@unlink($tmp . '.gz');
@unlink(preg_replace('/\.phar$/', '.tar', $tmp));
?>
--EXPECT--
compress=1
decompress=1
convertToData=1
convertToExecutable=1
isCompressed=1
isFileFormat=1
fmt_phar=1
fmt_tar=0
fmt_zip=0
comp0=F
gz_class=Phar
comp_gz=GZ
content=hi
plain_class=Phar
comp_plain=F
data_class=PharData
data_content=hi
exec_class=Phar

--TEST--
stdlib PharData isFileFormat/getModified/count/isWritable/isCompressed (#21692)
--FILE--
<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21692_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$path = $dir . '/pint.tar';
@unlink($path);

$p = new PharData($path);
$p['a.txt'] = 'hi';
echo $p->isFileFormat(Phar::TAR) ? 'tar' : 'nottar', "\n";
echo $p->isFileFormat(Phar::ZIP) ? 'zip' : 'notzip', "\n";
echo $p->getModified() ? 'mod' : 'clean', "\n";
echo $p->count(), "\n";
echo $p->isWritable() ? 'y' : 'n', "\n";
echo $p->isCompressed() ? 'y' : 'n', "\n";
echo 'ok', "\n";
--EXPECT--
tar
notzip
clean
1
y
n
ok

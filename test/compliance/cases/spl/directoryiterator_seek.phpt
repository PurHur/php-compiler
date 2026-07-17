--TEST--
SPL DirectoryIterator/FilesystemIterator::seek() (#19795, ext/spl/spl_directory.c)
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phpc_di_seek_' . getmypid();
@mkdir($dir);
$p = $dir . '/a.txt';
file_put_contents($p, 'a');

$di = new DirectoryIterator($dir);
$di->seek(0);
echo 'di0=', $di->getFilename(), ' key=', $di->key(), "\n";
$di->seek(1);
echo 'di1=', $di->getFilename(), ' key=', $di->key(), "\n";

$count = 0;
foreach (new DirectoryIterator($dir) as $_) {
    ++$count;
}
$di->seek($count);
echo 'di_past=', (int) $di->valid(), ' key=', $di->key(), "\n";
try {
    $di->seek($count + 1);
    echo "di_oob=ok\n";
} catch (OutOfBoundsException $e) {
    echo 'di_oob=', $e->getMessage(), "\n";
}

$fi = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
$fi->seek(0);
$fsName = $fi->getFilename();
echo ('.' !== $fsName && '..' !== $fsName) ? "fs0=nondot\n" : "fs0=dot\n";
try {
    $fi->seek(50);
    echo "fs_oob=ok\n";
} catch (OutOfBoundsException $e) {
    echo 'fs_oob=', $e->getMessage(), "\n";
}

@unlink($p);
@rmdir($dir);
?>
--EXPECT--
di0=. key=0
di1=.. key=1
di_past=0 key=3
di_oob=Seek position 4 is out of range
fs0=nondot
fs_oob=Seek position 50 is out of range

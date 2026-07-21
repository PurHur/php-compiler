--TEST--
stdlib PharData ZIP open + isFileFormat after convertToData (#21676, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phar_data_zip_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$pharPath = $dir . '/app.phar';
if (is_file($pharPath)) {
    unlink($pharPath);
}
$zipPath = $dir . '/app.zip';
if (is_file($zipPath)) {
    unlink($zipPath);
}
$p = new Phar($pharPath);
$p->addFromString('x.php', '<?php echo 1;');
$p->addFromString('sub/y.txt', 'hello');
$d = $p->convertToData(Phar::ZIP);
$path = $d->getPath();
echo 'inmem=', $d->isFileFormat(Phar::ZIP) ? 'Z' : 'T', "\n";
$d2 = new PharData($path);
echo 'x=', $d2['x.php']->getContent(), "\n";
echo 'y=', $d2['sub/y.txt']->getContent(), "\n";
echo 'fmt=', $d2->isFileFormat(Phar::ZIP) ? 'Z' : 'T', $d2->isFileFormat(Phar::TAR) ? 'T' : 'n', "\n";
?>
--EXPECT--
inmem=Z
x=<?php echo 1;
y=hello
fmt=Zn

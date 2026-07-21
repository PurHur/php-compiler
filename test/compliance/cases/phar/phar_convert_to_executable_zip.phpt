--TEST--
stdlib Phar::convertToExecutable(Phar::ZIP) returns .phar.zip (#21678, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phar_cte_zip_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$pharPath = $dir . '/app.phar';
$zipPath = $dir . '/app.phar.zip';
if (is_file($pharPath)) {
    unlink($pharPath);
}
if (is_file($zipPath)) {
    unlink($zipPath);
}
$p = new Phar($pharPath);
$p->addFromString('x.php', '<?php echo 1;');
$p->addFromString('sub/y.txt', 'hello');
$e = $p->convertToExecutable(Phar::ZIP);
echo 'class=', $e instanceof Phar ? 'Phar' : get_class($e), "\n";
$path = $e->getPath();
echo 'ext=', str_ends_with(strtolower($path), '.phar.zip') ? 'phar.zip' : 'other', "\n";
echo 'zip=', $e->isFileFormat(Phar::ZIP) ? '1' : '0', "\n";
echo 'tar=', $e->isFileFormat(Phar::TAR) ? '1' : '0', "\n";
echo 'x=', $e['x.php']->getContent(), "\n";
echo 'y=', $e['sub/y.txt']->getContent(), "\n";
$bin = file_get_contents($path);
echo 'magic=', (is_string($bin) && strlen($bin) >= 2 && $bin[0] === 'P' && $bin[1] === 'K') ? 'PK' : 'NO', "\n";
$e2 = new Phar($path);
echo 'reopen=', $e2['x.php']->getContent(), "\n";
try {
    $p->convertToExecutable(Phar::ZIP, Phar::GZ);
    echo "gz=ok\n";
} catch (BadMethodCallException $ex) {
    echo 'gz=BadMethodCallException', "\n";
}
?>
--EXPECT--
class=Phar
ext=phar.zip
zip=1
tar=0
x=<?php echo 1;
y=hello
magic=PK
reopen=<?php echo 1;
gz=BadMethodCallException

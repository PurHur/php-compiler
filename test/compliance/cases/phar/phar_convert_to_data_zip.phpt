--TEST--
stdlib Phar::convertToData(Phar::ZIP) returns PharData zip (#21675, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
$dir = sys_get_temp_dir() . '/phar_ctd_zip_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$pharPath = $dir . '/app.phar';
$zipPath = $dir . '/app.zip';
if (is_file($pharPath)) {
    unlink($pharPath);
}
if (is_file($zipPath)) {
    unlink($zipPath);
}
$p = new Phar($pharPath);
$p->addFromString('x.php', '<?php echo 1;');
$p->addFromString('sub/y.txt', 'hello');
$d = $p->convertToData(Phar::ZIP);
echo 'class=', $d instanceof PharData ? 'PharData' : get_class($d), "\n";
$path = $d->getPath();
echo 'ext=', str_ends_with(strtolower($path), '.zip') ? 'zip' : 'other', "\n";
echo 'x=', $d['x.php']->getContent(), "\n";
echo 'y=', $d['sub/y.txt']->getContent(), "\n";
$bin = file_get_contents($path);
echo 'magic=', (is_string($bin) && strlen($bin) >= 2 && $bin[0] === 'P' && $bin[1] === 'K') ? 'PK' : 'NO', "\n";
?>
--EXPECT--
class=PharData
ext=zip
x=<?php echo 1;
y=hello
magic=PK

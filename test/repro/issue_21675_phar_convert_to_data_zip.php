<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21675_' . getmypid() . '_' . mt_rand();
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
$p->addEmptyDir('sub');
$p->addFromString('sub/y.txt', 'hello');

$d = $p->convertToData(Phar::ZIP);
echo 'class=', $d instanceof PharData ? 'PharData' : get_class($d), "\n";
$path = $d->getPath();
echo 'ext=', str_ends_with(strtolower($path), '.zip') ? 'zip' : $path, "\n";
echo 'x=', $d['x.php']->getContent(), "\n";
echo 'y=', $d['sub/y.txt']->getContent(), "\n";
$bin = file_get_contents($path);
echo 'magic=', (is_string($bin) && strlen($bin) >= 4 && substr($bin, 0, 2) === 'PK') ? 'PK' : 'NO', "\n";
echo 'size=', (is_string($bin) && strlen($bin) > 0) ? 'Y' : 'N', "\n";

try {
    $p->convertToData(999);
    echo "bad_fmt=ok\n";
} catch (Throwable $e) {
    echo 'bad_fmt=', $e::class, "\n";
}

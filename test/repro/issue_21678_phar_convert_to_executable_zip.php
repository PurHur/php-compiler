<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21678_' . getmypid() . '_' . mt_rand();
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
$p->addEmptyDir('sub');
$p->addFromString('sub/y.txt', 'hello');

$e = $p->convertToExecutable(Phar::ZIP);
echo 'class=', $e instanceof Phar ? 'Phar' : get_class($e), "\n";
$path = $e->getPath();
echo 'ext=', str_ends_with(strtolower($path), '.phar.zip') ? 'phar.zip' : $path, "\n";
echo 'zip=', $e->isFileFormat(Phar::ZIP) ? '1' : '0', "\n";
echo 'tar=', $e->isFileFormat(Phar::TAR) ? '1' : '0', "\n";
echo 'x=', $e['x.php']->getContent(), "\n";
echo 'y=', $e['sub/y.txt']->getContent(), "\n";
$bin = file_get_contents($path);
echo 'magic=', (is_string($bin) && strlen($bin) >= 2 && substr($bin, 0, 2) === 'PK') ? 'PK' : 'NO', "\n";
echo 'halt=', (is_string($bin) && str_contains($bin, '__HALT_COMPILER()')) ? '1' : '0', "\n";

$e2 = new Phar($path);
echo 'reopen_x=', $e2['x.php']->getContent(), "\n";
echo 'reopen_zip=', $e2->isFileFormat(Phar::ZIP) ? '1' : '0', "\n";

try {
    $p->convertToExecutable(Phar::ZIP, Phar::GZ);
    echo "gz=ok\n";
} catch (Throwable $ex) {
    echo 'gz=', $ex::class, "\n";
}

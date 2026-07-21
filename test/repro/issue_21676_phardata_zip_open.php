<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21676_' . getmypid() . '_' . mt_rand();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$pharPath = $dir . '/app.phar';
if (is_file($pharPath)) {
    unlink($pharPath);
}

$p = new Phar($pharPath);
$p->addFromString('x.php', '<?php echo 1;');
$p->addFromString('sub/y.txt', 'hello');
$d = $p->convertToData(Phar::ZIP);
$path = $d->getPath();
echo 'inmem_zip=', $d->isFileFormat(Phar::ZIP) ? 'Y' : 'N', ' tar=', $d->isFileFormat(Phar::TAR) ? 'Y' : 'N', "\n";

$d2 = new PharData($path);
echo 'reopen_x=', $d2['x.php']->getContent(), "\n";
echo 'reopen_y=', $d2['sub/y.txt']->getContent(), "\n";
echo 'fmt_zip=', $d2->isFileFormat(Phar::ZIP) ? 'Y' : 'N', "\n";
echo 'fmt_tar=', $d2->isFileFormat(Phar::TAR) ? 'Y' : 'N', "\n";

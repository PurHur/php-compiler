<?php
declare(strict_types=1);

foreach (['compress', 'decompress', 'isCompressed'] as $m) {
    echo $m, ' ', method_exists(PharFileInfo::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/pfi21653_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('a.txt', 'hello');
$phar->stopBuffering();

$fi = $phar['a.txt'];
echo 'idle=', $fi->isCompressed() ? 'Y' : 'N', ' gz=', $fi->isCompressed(Phar::GZ) ? 'Y' : 'N', "\n";
$fi->compress(Phar::GZ);
echo 'after=', $fi->isCompressed() ? 'Y' : 'N', ' gz=', $fi->isCompressed(Phar::GZ) ? 'Y' : 'N', ' bz=', $fi->isCompressed(Phar::BZ2) ? 'Y' : 'N', "\n";
echo 'flags=', $fi->getPharFlags(), "\n";
echo 'content=', $fi->getContent(), "\n";
unset($fi, $phar);

$phar2 = new Phar($pharPath);
$fi2 = $phar2['a.txt'];
echo 'reopen=', $fi2->isCompressed() ? 'Y' : 'N', ' gz=', $fi2->isCompressed(Phar::GZ) ? 'Y' : 'N', "\n";
$fi2->decompress();
echo 'dec=', $fi2->isCompressed() ? 'Y' : 'N', "\n";

try {
    $fi2->compress(999);
    echo "bad=ok\n";
} catch (Throwable $e) {
    echo 'bad=', $e::class, ':', $e->getMessage(), "\n";
}

if (!extension_loaded('bz2')) {
    try {
        $fi2->compress(Phar::BZ2);
        echo "bz2=ok\n";
    } catch (Throwable $e) {
        echo (str_contains($e->getMessage(), 'bz2 extension is not enabled'))
            ? "bz2=missing\n"
            : ('bz2=other:'.$e->getMessage()."\n");
    }
} else {
    echo "bz2=present\n";
}

@unlink($pharPath);
@rmdir($dir);

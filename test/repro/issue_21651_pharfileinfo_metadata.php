<?php
declare(strict_types=1);

foreach (['hasMetadata', 'getMetadata', 'setMetadata', 'delMetadata'] as $m) {
    echo $m, ' ', method_exists(PharFileInfo::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/pfi21651_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
$phar->stopBuffering();

$fi = $phar['a.txt'];
echo 'idle_has=', $fi->hasMetadata() ? 'Y' : 'N', "\n";
$fi->setMetadata(['k' => 1, 's' => 'v']);
echo 'has=', $fi->hasMetadata() ? 'Y' : 'N', "\n";
$meta = $fi->getMetadata();
echo 'meta=', is_array($meta) && ($meta['k'] ?? null) === 1 && ($meta['s'] ?? null) === 'v' ? 'Y' : 'N', "\n";
unset($fi, $phar);

$phar2 = new Phar($pharPath);
$fi2 = $phar2['a.txt'];
echo 'reopen_has=', $fi2->hasMetadata() ? 'Y' : 'N', "\n";
$meta2 = $fi2->getMetadata();
echo 'reopen_meta=', is_array($meta2) && ($meta2['k'] ?? null) === 1 ? 'Y' : 'N', "\n";
echo 'del=', $fi2->delMetadata() ? 'Y' : 'N', "\n";
echo 'del_has=', $fi2->hasMetadata() ? 'Y' : 'N', "\n";
var_dump($fi2->getMetadata());
unset($fi2, $phar2);

$phar3 = new Phar($pharPath);
$fi3 = $phar3['a.txt'];
echo 'after_del_has=', $fi3->hasMetadata() ? 'Y' : 'N', "\n";
@unlink($pharPath);
@rmdir($dir);

<?php
/**
 * Issue #21229 — Phar metadata get/set/has/del after #20628/#21228.
 */
declare(strict_types=1);

foreach (['setMetadata', 'getMetadata', 'hasMetadata', 'delMetadata'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21229_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
echo 'idle_has=', $phar->hasMetadata() ? 'Y' : 'N', "\n";
$phar->setMetadata(['k' => 1, 's' => 'v']);
echo 'has=', $phar->hasMetadata() ? 'Y' : 'N', "\n";
$meta = $phar->getMetadata();
echo 'meta=', is_array($meta) && ($meta['k'] ?? null) === 1 && ($meta['s'] ?? null) === 'v' ? 'Y' : 'N', "\n";
$phar->stopBuffering();
unset($phar);

$phar2 = new Phar($pharPath);
echo 'reopen_has=', $phar2->hasMetadata() ? 'Y' : 'N', "\n";
$meta2 = $phar2->getMetadata();
echo 'reopen_meta=', is_array($meta2) && ($meta2['k'] ?? null) === 1 ? 'Y' : 'N', "\n";
echo 'count=', $phar2->count(), "\n";
$phar2->delMetadata();
echo 'del_has=', $phar2->hasMetadata() ? 'Y' : 'N', "\n";
unset($phar2);

$phar3 = new Phar($pharPath);
echo 'after_del_has=', $phar3->hasMetadata() ? 'Y' : 'N', "\n";
var_dump($phar3->getMetadata());

$phar3->setMetadata(null);
echo 'null_has=', $phar3->hasMetadata() ? 'Y' : 'N', "\n";
var_dump($phar3->getMetadata());

echo "ok\n";

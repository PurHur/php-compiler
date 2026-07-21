<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21690_' . getmypid() . '_' . mt_rand();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$tar = $dir . '/pcd.tar';
if (is_file($tar)) {
    unlink($tar);
}

$p = new PharData($tar);
$p['a.txt'] = 'hi';
$p->copy('a.txt', 'b.txt');
echo 'copy=', $p['b.txt']->getContent(), "\n";
$p->delete('a.txt');
echo 'del=', isset($p['a.txt']) ? 'still' : 'gone', "\n";
echo 'b=', isset($p['b.txt']) ? 'yes' : 'no', "\n";

try {
    $p->delete('missing.txt');
    echo "miss=ok\n";
} catch (Throwable $e) {
    echo 'miss=', $e::class, "\n";
}

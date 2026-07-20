<?php
/**
 * Issue #21228 — Phar::delete()/count()/isBuffering() after #20628.
 */
declare(strict_types=1);

foreach (['delete', 'count', 'isBuffering'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21228_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->startBuffering();
echo 'buf=', $phar->isBuffering() ? 'Y' : 'N', "\n";
$phar->addFromString('a.txt', 'A');
$phar->addFromString('b.txt', 'B');
$phar->addEmptyDir('subdir');
echo 'count=', $phar->count(), ' count_fn=', count($phar), "\n";
$phar->stopBuffering();
echo 'buf2=', $phar->isBuffering() ? 'Y' : 'N', "\n";

echo 'del=', $phar->delete('a.txt') ? 'Y' : 'N', "\n";
echo 'after=', $phar->count(), ' a=', $phar->offsetExists('a.txt') ? 'Y' : 'N', "\n";

try {
    $phar->delete('missing');
    echo "missing=ok\n";
} catch (BadMethodCallException $e) {
    echo 'missing=', $e->getMessage(), "\n";
}

echo "ok\n";

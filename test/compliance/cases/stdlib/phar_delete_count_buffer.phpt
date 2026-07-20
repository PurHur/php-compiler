--TEST--
stdlib Phar delete/count/isBuffering (#21228)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['delete', 'count', 'isBuffering'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21228_c_' . getmypid() . '_' . mt_rand();
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
    echo 'missing_ok=', str_contains($e->getMessage(), 'does not exist') ? 'Y' : 'N', "\n";
}

echo "ok\n";
--EXPECT--
delete Y
count Y
isBuffering Y
buf=Y
count=3 count_fn=3
buf2=N
del=Y
after=2 a=N
missing_ok=Y
ok

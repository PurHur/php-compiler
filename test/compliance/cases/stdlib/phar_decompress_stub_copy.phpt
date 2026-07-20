--TEST--
stdlib Phar decompressFiles/setDefaultStub/copy (#21231)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['decompressFiles', 'setDefaultStub', 'copy'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21231_c_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('a.txt', 'AAA');
echo 'copy=', $phar->copy('a.txt', 'b.txt') ? 'Y' : 'N', "\n";
echo 'count=', $phar->count(), ' has_b=', $phar->offsetExists('b.txt') ? 'Y' : 'N', "\n";
echo 'stub1=', $phar->setDefaultStub('cli.php', 'web.php') ? 'Y' : 'N', "\n";
echo 'stub_has_web=', str_contains($phar->getStub(), 'web.php') ? 'Y' : 'N', "\n";
echo 'decomp=', $phar->decompressFiles() ? 'Y' : 'N', "\n";
$phar->stopBuffering();

try {
    $phar->copy('a.txt', 'a.txt');
    echo "copy_same=ok\n";
} catch (UnexpectedValueException $e) {
    echo 'copy_same=', str_contains($e->getMessage(), 'must not already exist') ? 'Y' : 'N', "\n";
}

try {
    $phar->copy('missing', 'x.txt');
    echo "copy_missing=ok\n";
} catch (UnexpectedValueException $e) {
    echo 'copy_missing=', str_contains($e->getMessage(), 'does not exist') ? 'Y' : 'N', "\n";
}

echo "ok\n";
--EXPECT--
decompressFiles Y
setDefaultStub Y
copy Y
copy=Y
count=2 has_b=Y
stub1=Y
stub_has_web=Y
decomp=Y
copy_same=Y
copy_missing=Y
ok

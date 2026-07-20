--TEST--
stdlib Phar loadPhar/unlinkArchive (#21232)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['loadPhar', 'unlinkArchive'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21232_c_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->addFromString('a.txt', 'payload');
unset($phar);

echo 'load=', Phar::loadPhar($pharPath, 'myalias') ? 'Y' : 'N', "\n";
echo 'unlink=', Phar::unlinkArchive($pharPath) ? 'Y' : 'N', "\n";
echo 'exists=', file_exists($pharPath) ? 'Y' : 'N', "\n";

$pharPath2 = $dir . '/busy.phar';
@unlink($pharPath2);
$open = new Phar($pharPath2);
$open->addFromString('x.txt', '1');
try {
    Phar::unlinkArchive($pharPath2);
    echo "busy=ok\n";
} catch (PharException $e) {
    echo 'busy=', str_contains($e->getMessage(), 'open file handles') ? 'Y' : 'N', "\n";
}

echo "ok\n";
--EXPECT--
loadPhar Y
unlinkArchive Y
load=Y
unlink=Y
exists=N
busy=Y
ok

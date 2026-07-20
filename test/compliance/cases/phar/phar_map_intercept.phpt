--TEST--
ext/phar Phar::mapPhar/interceptFileFuncs + phar:// read (#21338, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['mapPhar', 'interceptFileFuncs'] as $m) {
    echo $m, '=', method_exists('Phar', $m) ? 'Y' : 'N', "\n";
}

try {
    Phar::mapPhar();
    echo "map_fail=N\n";
} catch (PharException $e) {
    echo 'map_fail=', str_contains($e->getMessage(), 'phar archive') ? 'Y' : 'N', "\n";
}

Phar::interceptFileFuncs();
echo "intercept=ok\n";

$dir = sys_get_temp_dir() . '/phar21338_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->addFromString('inner.txt', 'phar-payload');
unset($phar);

Phar::loadPhar($pharPath, 'appalias');
$viaAlias = file_get_contents('phar://appalias/inner.txt');
echo 'alias_read=', ($viaAlias === 'phar-payload') ? 'Y' : 'N', "\n";

$viaAbs = file_get_contents('phar://' . $pharPath . '/inner.txt');
echo 'abs_read=', ($viaAbs === 'phar-payload') ? 'Y' : 'N', "\n";
?>
--EXPECT--
mapPhar=Y
interceptFileFuncs=Y
map_fail=Y
intercept=ok
alias_read=Y
abs_read=Y

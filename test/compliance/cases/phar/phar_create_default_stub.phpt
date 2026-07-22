--TEST--
ext/phar Phar::createDefaultStub Zend shortarc body (#22292, ext/phar/phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);
$s = Phar::createDefaultStub('index.php', 'cli.php');
echo 'len=', strlen($s), "\n";
echo 'intercept=', str_contains($s, 'interceptFileFuncs') ? 'Y' : 'N', "\n";
echo 'webPhar=', str_contains($s, 'webPhar') ? 'Y' : 'N', "\n";
echo 'Extract_Phar=', str_contains($s, 'Extract_Phar') ? 'Y' : 'N', "\n";
echo 'shebang=', str_starts_with($s, '#!') ? 'Y' : 'N', "\n";
echo 'start=', str_contains($s, "const START = 'index.php'") ? 'Y' : 'N', "\n";
echo 'web=', str_contains($s, "\$web = 'cli.php'") ? 'Y' : 'N', "\n";
$s2 = Phar::createDefaultStub('foo.php');
echo 'foo_start=', str_contains($s2, "const START = 'foo.php'") ? 'Y' : 'N', "\n";
echo 'foo_web=', str_contains($s2, "\$web = 'index.php'") ? 'Y' : 'N', "\n";
$dir = sys_get_temp_dir() . '/phar22292_' . getmypid();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('index.php', '<?php echo "ok";');
$phar->setDefaultStub('index.php', 'cli.php');
$phar->stopBuffering();
$got = $phar->getStub();
echo 'setDefault=', (is_string($got) && str_contains($got, 'interceptFileFuncs') && str_contains($got, "\$web = 'cli.php'")) ? 'Y' : 'N', "\n";
$longOk = 'N';
try {
    Phar::createDefaultStub(str_repeat('x', 401));
} catch (PharException $e) {
    $longOk = str_contains($e->getMessage(), '400 or less') ? 'Y' : 'N';
}
echo 'long=', $longOk, "\n";
@unlink($pharPath);
@rmdir($dir);
?>
--EXPECT--
len=6639
intercept=Y
webPhar=Y
Extract_Phar=Y
shebang=N
start=Y
web=Y
foo_start=Y
foo_web=Y
setDefault=Y
long=Y

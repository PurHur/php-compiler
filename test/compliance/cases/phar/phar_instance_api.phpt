--TEST--
ext/phar Phar instance build/add/extract/stub (#20628)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);
foreach (['buildFromDirectory','addFile','extractTo','setStub','offsetGet','addFromString','addEmptyDir','getStub','setAlias','startBuffering','stopBuffering','compressFiles'] as $m) {
    echo $m, '=', method_exists(Phar::class, $m) ? '1' : '0', "\n";
}
$dir = sys_get_temp_dir() . '/phar20628_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('hello.txt', 'world');
$phar->addEmptyDir('sub');
$stubCode = Phar::createDefaultStub('hello.txt');
$phar->setStub($stubCode);
$phar->setAlias('app.phar');
$phar->stopBuffering();
echo 'content=', $phar['hello.txt']->getContent(), "\n";
echo 'alias=', $phar->getAlias(), "\n";
$gotStub = $phar->getStub();
echo 'stub=', is_string($gotStub) && str_contains($gotStub, '__HALT_COMPILER()') ? '1' : '0', "\n";
$out = $dir . '/out';
@mkdir($out, 0777, true);
echo 'extract=', (int) $phar->extractTo($out), "\n";
$extracted = $out . '/hello.txt';
echo 'file=', is_file($extracted) ? (string) file_get_contents($extracted) : 'MISSING', "\n";
?>
--EXPECT--
buildFromDirectory=1
addFile=1
extractTo=1
setStub=1
offsetGet=1
addFromString=1
addEmptyDir=1
getStub=1
setAlias=1
startBuffering=1
stopBuffering=1
compressFiles=1
content=world
alias=app.phar
stub=1
extract=1
file=world

--TEST--
stdlib Phar getVersion/isWritable/getModified (#21230)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['getVersion', 'isWritable', 'getModified'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar21230_c_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
echo 'ver=', $phar->getVersion(), ' api=', Phar::apiVersion(), "\n";
echo 'writable=', $phar->isWritable() ? 'Y' : 'N', ' canWrite=', Phar::canWrite() ? 'Y' : 'N', "\n";
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
echo 'buf_mod=', $phar->getModified() ? 'Y' : 'N', "\n";
$phar->stopBuffering();
echo 'flushed_mod=', $phar->getModified() ? 'Y' : 'N', "\n";
unset($phar);

$phar2 = new Phar($pharPath);
echo 'reopen_mod=', $phar2->getModified() ? 'Y' : 'N', "\n";
echo 'reopen_ver=', $phar2->getVersion(), "\n";

echo "ok\n";
--EXPECT--
getVersion Y
isWritable Y
getModified Y
ver=1.1.1 api=1.1.1
writable=Y canWrite=Y
buf_mod=Y
flushed_mod=N
reopen_mod=N
reopen_ver=1.1.1
ok

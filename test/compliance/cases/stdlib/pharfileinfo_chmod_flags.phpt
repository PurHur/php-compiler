--TEST--
stdlib PharFileInfo chmod/getPharFlags (#21652)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);

foreach (['chmod', 'getPharFlags', 'getPerms'] as $m) {
    echo $m, ' ', method_exists(PharFileInfo::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/pfi21652c_' . getmypid() . '_' . mt_rand();
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
@unlink($pharPath);

$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('a.txt', 'hi');
$phar->stopBuffering();

$fi = $phar['a.txt'];
$p0 = $fi->getPerms();
echo 'perms=', substr(sprintf('%o', $p0), -4), "\n";
echo 'flags=', $fi->getPharFlags(), "\n";
$fi->chmod(0444);
$p1 = $fi->getPerms();
echo 'after=', substr(sprintf('%o', $p1), -4), "\n";
$fi->chmod(0755);
$p2 = $fi->getPerms();
echo 'exec=', substr(sprintf('%o', $p2), -4), "\n";
unset($fi, $phar);

$phar2 = new Phar($pharPath);
$fi2 = $phar2['a.txt'];
$p3 = $fi2->getPerms();
echo 'reopen=', substr(sprintf('%o', $p3), -4), "\n";
echo 'reopen_flags=', $fi2->getPharFlags(), "\n";
@unlink($pharPath);
@rmdir($dir);
--EXPECT--
chmod Y
getPharFlags Y
getPerms Y
perms=0644
flags=0
after=0444
exec=0755
reopen=0755
reopen_flags=0

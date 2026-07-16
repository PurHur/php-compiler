<?php
declare(strict_types=1);

$path = __FILE__;
$f = new SplFileInfo($path);
echo 'exists=', method_exists($f, 'getType') ? '1' : '0', "\n";
echo 'getType=', $f->getType(), "\n";
echo 'isLink=', (int) $f->isLink(), "\n";
echo 'perms=', decoct($f->getPerms() & 0777), "\n";
echo 'owner=', (int) ($f->getOwner() >= 0), "\n";
echo 'group=', (int) ($f->getGroup() >= 0), "\n";
echo 'atime=', (int) ($f->getATime() > 0), "\n";
echo 'inode=', (int) ($f->getInode() > 0), "\n";
echo 'exec=', (int) $f->isExecutable(), "\n";

$dir = sys_get_temp_dir().'/sfi_stat_'.getmypid();
@mkdir($dir);
$target = $dir.'/t.txt';
file_put_contents($target, 'x');
$link = $dir.'/l.txt';
@unlink($link);
symlink($target, $link);
$lf = new SplFileInfo($link);
echo 'linkType=', $lf->getType(), "\n";
echo 'linkIsLink=', (int) $lf->isLink(), "\n";
echo 'linkTarget=', $lf->getLinkTarget(), "\n";
try {
    $f->getLinkTarget();
    echo "nolt-bad\n";
} catch (RuntimeException $e) {
    echo (str_contains($e->getMessage(), 'Unable to read link') ? 'nolt-ok' : 'nolt-msg'), "\n";
}
try {
    (new SplFileInfo('/no/such/spl_fileinfo_stat'))->getType();
    echo "miss-bad\n";
} catch (RuntimeException $e) {
    echo (str_contains($e->getMessage(), 'Lstat failed') ? 'miss-ok' : 'miss-msg'), "\n";
}
@unlink($link);
@unlink($target);
@rmdir($dir);

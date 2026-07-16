<?php
declare(strict_types=1);

$path = __FILE__;
$f = new SplFileInfo($path);
echo method_exists($f, 'getType') ? "exists-ok\n" : "exists-bad\n";
echo 'file' === $f->getType() ? "type-ok\n" : "type-bad\n";
echo !$f->isLink() ? "islink-ok\n" : "islink-bad\n";
echo ($f->getPerms() & 0777) > 0 ? "perms-ok\n" : "perms-bad\n";
echo $f->getOwner() >= 0 ? "owner-ok\n" : "owner-bad\n";
echo $f->getGroup() >= 0 ? "group-ok\n" : "group-bad\n";
echo $f->getATime() > 0 ? "atime-ok\n" : "atime-bad\n";
echo $f->getInode() > 0 ? "inode-ok\n" : "inode-bad\n";
echo \is_bool($f->isExecutable()) ? "exec-ok\n" : "exec-bad\n";

$dir = sys_get_temp_dir().'/sfi_cmp_'.getmypid();
@mkdir($dir);
$target = $dir.'/t.txt';
file_put_contents($target, 'x');
$link = $dir.'/l.txt';
@unlink($link);
symlink($target, $link);
$lf = new SplFileInfo($link);
echo 'link' === $lf->getType() ? "linktype-ok\n" : "linktype-bad\n";
echo $lf->isLink() ? "linkis-ok\n" : "linkis-bad\n";
echo $lf->getLinkTarget() === $target ? "linktgt-ok\n" : "linktgt-bad\n";
try {
    $f->getLinkTarget();
    echo "nolt-bad\n";
} catch (RuntimeException $e) {
    echo str_contains($e->getMessage(), 'Unable to read link') ? "nolt-ok\n" : "nolt-msg\n";
}
@unlink($link);
@unlink($target);
@rmdir($dir);

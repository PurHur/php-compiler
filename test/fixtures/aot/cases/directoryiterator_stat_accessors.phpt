--TEST--
AOT: DirectoryIterator getMTime/getATime/getCTime/getPerms/getOwner/getGroup/getInode (#33283)
--FILE--
<?php
$dir = 'test/fixtures/aot/cases/directoryiterator_27289_fixture';
$it = new DirectoryIterator($dir);
foreach ($it as $f) {
    if ($f->isDot()) {
        continue;
    }
    if (!$f->isFile()) {
        continue;
    }
    echo 'name=', $f->getFilename(),
        ' perms=', decoct($f->getPerms()),
        ' mtime_ok=', $f->getMTime() > 0 ? '1' : '0',
        ' atime_ok=', $f->getATime() > 0 ? '1' : '0',
        ' ctime_ok=', $f->getCTime() > 0 ? '1' : '0',
        ' owner_ok=', $f->getOwner() >= 0 ? '1' : '0',
        ' group_ok=', $f->getGroup() >= 0 ? '1' : '0',
        ' inode_ok=', $f->getInode() > 0 ? '1' : '0',
        "\n";
    break;
}
--EXPECT--
name=a.txt perms=100644 mtime_ok=1 atime_ok=1 ctime_ok=1 owner_ok=1 group_ok=1 inode_ok=1

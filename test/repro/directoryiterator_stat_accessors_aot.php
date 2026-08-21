<?php
/**
 * #33283 — AOT DirectoryIterator SplFileInfo stat accessors must match Zend.
 *
 * Peer of #33276 getSize. Fixture under test/fixtures/aot/cases/ (avoid mkdir).
 * Assert stable perms + positive timestamps (inode/uid vary by host).
 */
$dir = __DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture';
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

<?php
/**
 * #33282 — AOT DirectoryIterator getMTime/getPerms/getInode/… must match Zend
 * (no object::getmtime / getperms / … abort).
 *
 * Fixture dir is committed under test/fixtures/aot/cases/ (avoid mkdir/rmdir in AOT repro).
 * Timestamps/inode/owner are FS-local — compare Zend vs AOT in the same container.
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
    echo 'name:', $f->getFilename(), "\n";
    echo 'mtime:', $f->getMTime(), "\n";
    echo 'atime:', $f->getATime(), "\n";
    echo 'ctime:', $f->getCTime(), "\n";
    echo 'perms:', $f->getPerms(), "\n";
    echo 'inode:', $f->getInode(), "\n";
    echo 'owner:', $f->getOwner(), "\n";
    echo 'group:', $f->getGroup(), "\n";
    break;
}

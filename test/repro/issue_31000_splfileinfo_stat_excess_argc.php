<?php

/**
 * Repro #31000 — SplFileInfo zero-arg stat/predicate excess argc.
 * php-src: ext/spl/spl_directory.c
 */
$f = new SplFileInfo('/etc/hosts');
$methods = [
    'getExtension',
    'isFile',
    'isDir',
    'isLink',
    'isReadable',
    'getInode',
    'getOwner',
    'getGroup',
    'getATime',
    'getMTime',
    'getCTime',
    'getPerms',
    'getType',
];
foreach ($methods as $m) {
    try {
        $f->$m(1);
        echo $m, '=acc', "\n";
    } catch (Throwable $e) {
        echo $m, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
$ok = is_string($f->getExtension())
    && is_bool($f->isFile())
    && is_bool($f->isDir())
    && is_bool($f->isLink())
    && is_bool($f->isReadable())
    && is_int($f->getInode())
    && is_int($f->getOwner())
    && is_int($f->getGroup())
    && is_int($f->getATime())
    && is_int($f->getMTime())
    && is_int($f->getCTime())
    && is_int($f->getPerms())
    && is_string($f->getType());
echo 'ok=', $ok ? '1' : '0', "\n";

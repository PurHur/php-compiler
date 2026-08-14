--TEST--
spl: SplFileInfo stat/predicate ArgumentCountError JIT (#31000)
--FILE--
<?php
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
        echo $m, ' acc', "\n";
    } catch (Throwable $e) {
        echo $m, ' ', get_class($e), ': ', $e->getMessage(), "\n";
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
--EXPECT--
getExtension ArgumentCountError: SplFileInfo::getExtension() expects exactly 0 arguments, 1 given
isFile ArgumentCountError: SplFileInfo::isFile() expects exactly 0 arguments, 1 given
isDir ArgumentCountError: SplFileInfo::isDir() expects exactly 0 arguments, 1 given
isLink ArgumentCountError: SplFileInfo::isLink() expects exactly 0 arguments, 1 given
isReadable ArgumentCountError: SplFileInfo::isReadable() expects exactly 0 arguments, 1 given
getInode ArgumentCountError: SplFileInfo::getInode() expects exactly 0 arguments, 1 given
getOwner ArgumentCountError: SplFileInfo::getOwner() expects exactly 0 arguments, 1 given
getGroup ArgumentCountError: SplFileInfo::getGroup() expects exactly 0 arguments, 1 given
getATime ArgumentCountError: SplFileInfo::getATime() expects exactly 0 arguments, 1 given
getMTime ArgumentCountError: SplFileInfo::getMTime() expects exactly 0 arguments, 1 given
getCTime ArgumentCountError: SplFileInfo::getCTime() expects exactly 0 arguments, 1 given
getPerms ArgumentCountError: SplFileInfo::getPerms() expects exactly 0 arguments, 1 given
getType ArgumentCountError: SplFileInfo::getType() expects exactly 0 arguments, 1 given
ok=1

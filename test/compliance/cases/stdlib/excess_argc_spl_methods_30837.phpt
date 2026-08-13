--TEST--
ArrayObject/SplFileInfo/DirectoryIterator excess argc → ArgumentCountError (#30837)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/maintainer_gap_spl_methods_excess_argc_30837.php
--EXPECT--
exchangeArray: ArrayObject::exchangeArray() expects exactly 1 argument, 2 given
getIterator: ArrayObject::getIterator() expects exactly 0 arguments, 1 given
append: ArrayObject::append() expects exactly 1 argument, 2 given
getSize: SplFileInfo::getSize() expects exactly 0 arguments, 1 given
getPathname: SplFileInfo::getPathname() expects exactly 0 arguments, 1 given
getFilename: DirectoryIterator::getFilename() expects exactly 0 arguments, 1 given
isDot: DirectoryIterator::isDot() expects exactly 0 arguments, 1 given
ok_append: 2
ok_pathname: /etc/hosts

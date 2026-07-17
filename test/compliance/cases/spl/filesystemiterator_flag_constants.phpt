--TEST--
SPL FilesystemIterator FOLLOW_SYMLINKS + *_MODE_MASK constants (#20070, ext/spl/spl_directory.h)
--FILE--
<?php
echo FilesystemIterator::FOLLOW_SYMLINKS, "\n";
echo FilesystemIterator::CURRENT_MODE_MASK, "\n";
echo FilesystemIterator::KEY_MODE_MASK, "\n";
echo FilesystemIterator::OTHER_MODE_MASK, "\n";
$cur = FilesystemIterator::CURRENT_MODE_MASK & FilesystemIterator::CURRENT_AS_SELF;
$key = FilesystemIterator::KEY_MODE_MASK & FilesystemIterator::KEY_AS_FILENAME;
$oth = FilesystemIterator::OTHER_MODE_MASK & FilesystemIterator::FOLLOW_SYMLINKS;
$combo = FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::FOLLOW_SYMLINKS;
echo $cur, "\n";
echo $key, "\n";
echo $oth, "\n";
echo $combo, "\n";
$r = new ReflectionClass('FilesystemIterator');
echo count($r->getConstants()), "\n";
$dir = sys_get_temp_dir() . '/phpc_fsi_flags_' . getmypid();
@mkdir($dir);
$it = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);
$it->setFlags($combo);
echo $it->getFlags(), "\n";
@rmdir($dir);
?>
--EXPECT--
16384
240
3840
28672
16
256
16384
16640
12
16640

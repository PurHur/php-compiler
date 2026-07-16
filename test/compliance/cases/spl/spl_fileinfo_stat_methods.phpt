--TEST--
SPL SplFileInfo getType/isLink/getPerms/getOwner/getGroup/getATime/getInode/isExecutable/getLinkTarget (#19490, ext/spl/spl_directory.c)
--RUNFILE--
spl_fileinfo_stat_methods_run.php
--FILE--
<?php
// body in RUNFILE
--EXPECT--
exists-ok
type-ok
islink-ok
perms-ok
owner-ok
group-ok
atime-ok
inode-ok
exec-ok
linktype-ok
linkis-ok
linktgt-ok
nolt-ok

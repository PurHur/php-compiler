--TEST--
stdlib positive stat cache — filesize stays stale across rewrite until clearstatcache (issue #22841)
--FILE--
<?php
$f = tempnam(sys_get_temp_dir(), 'phpc_stat_pos_');
file_put_contents($f, 'x');
clearstatcache(true, $f);
$sz1 = filesize($f);
file_put_contents($f, 'hello');
$sz2 = filesize($f);
clearstatcache(true, $f);
$sz3 = filesize($f);
$mt1 = filemtime($f);
sleep(1);
file_put_contents($f, 'hello!');
$mt2 = filemtime($f);
clearstatcache(true, $f);
$mt3 = filemtime($f);
@unlink($f);
echo "sz1=$sz1\n";
echo "sz2_noclear=$sz2\n";
echo "sz3_clear=$sz3\n";
echo $mt1 === $mt2 ? "mtime_stale\n" : "mtime_fresh\n";
echo $mt3 > $mt1 ? "mtime_cleared\n" : "mtime_uncleared\n";
--EXPECT--
sz1=1
sz2_noclear=1
sz3_clear=5
mtime_stale
mtime_cleared

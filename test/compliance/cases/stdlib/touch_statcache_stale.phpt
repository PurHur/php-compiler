--TEST--
stdlib touch() keeps positive filemtime stale until clearstatcache (issue #25853; supersedes #25308)
--FILE--
<?php
$f = tempnam(sys_get_temp_dir(), 'phpc_touch_stat_');
$mt0 = filemtime($f);
touch($f, 100);
$mt_stale = filemtime($f);
clearstatcache(true, $f);
$mt_fresh = filemtime($f);
@unlink($f);
echo $mt_stale === $mt0 ? "stale\n" : "fresh\n";
echo "mt_fresh=$mt_fresh\n";

// After clear, a further touch still leaves a new positive hit stale until clear again.
clearstatcache(true, $f);
$f2 = tempnam(sys_get_temp_dir(), 'phpc_touch_stat2_');
touch($f2, 100);
$a = filemtime($f2);
touch($f2, 200);
$b = filemtime($f2);
clearstatcache(true, $f2);
$c = filemtime($f2);
@unlink($f2);
echo $b === $a ? "second_stale\n" : "second_fresh\n";
echo "after_clear=$c\n";
--EXPECT--
stale
mt_fresh=100
second_stale
after_clear=200

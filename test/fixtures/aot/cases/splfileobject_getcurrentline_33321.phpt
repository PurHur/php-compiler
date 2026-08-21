--TEST--
AOT: SplFileObject getCurrentLine advances like fgets (#33321)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_sfo_gcl_33321_fx.txt';
file_put_contents($p, "line1\nline2\nline3\n");
$o = new SplFileObject($p);
echo 'cur0=' . json_encode($o->current()) . "\n";
echo 'gcl1=' . json_encode($o->getCurrentLine()) . "\n";
echo 'cur1=' . json_encode($o->current()) . "\n";
echo 'fgets=' . json_encode($o->fgets()) . "\n";
echo 'cur2=' . json_encode($o->current()) . "\n";
@unlink($p);
?>
--EXPECT--
cur0="line1\n"
gcl1="line2\n"
cur1="line2\n"
fgets="line3\n"
cur2="line3\n"

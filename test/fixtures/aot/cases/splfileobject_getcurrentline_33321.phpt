--TEST--
AOT: SplFileObject getCurrentLine aliases fgets (#33321)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_sfo_gcl_33321_fx.txt';
file_put_contents($p, "line1\nline2\n");
$o = new SplFileObject($p);
echo 'gcl=' . json_encode($o->getCurrentLine()) . "\n";
echo 'cur=' . json_encode($o->current()) . "\n";
echo 'gcl2=' . json_encode($o->getCurrentLine()) . "\n";
@unlink($p);
?>
--EXPECT--
gcl="line1\n"
cur="line1\n"
gcl2="line2\n"

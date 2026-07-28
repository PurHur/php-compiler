--TEST--
SPL SplFileObject DROP_NEW_LINE trailing empty line is string (#24331, ext/spl/spl_directory.c)
--FILE--
<?php
$p = sys_get_temp_dir() . '/phpc_sfo_trail_' . getmypid() . '.txt';
file_put_contents($p, "L1\nL2\n");

$fo = new SplFileObject($p);
$fo->setFlags(SplFileObject::DROP_NEW_LINE);
foreach ($fo as $k => $line) {
    echo "d k=$k type=", gettype($line), ' val=', var_export($line, true), "\n";
}

$fo = new SplFileObject($p);
$fo->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::READ_AHEAD);
foreach ($fo as $k => $line) {
    echo "r k=$k type=", gettype($line), ' val=', var_export($line, true), "\n";
}

@unlink($p);
?>
--EXPECT--
d k=0 type=string val='L1'
d k=1 type=string val='L2'
d k=2 type=string val=''
r k=0 type=string val='L1'
r k=1 type=string val='L2'
r k=2 type=string val=''

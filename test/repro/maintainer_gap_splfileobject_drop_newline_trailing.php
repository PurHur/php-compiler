<?php
$p = sys_get_temp_dir() . '/sfo_trail_' . getmypid() . '.txt';
file_put_contents($p, "L1\nL2\n");

echo "DROP_NEW_LINE\n";
$fo = new SplFileObject($p);
$fo->setFlags(SplFileObject::DROP_NEW_LINE);
foreach ($fo as $k => $line) {
    echo "k=$k type=", gettype($line), ' val=', var_export($line, true), "\n";
}

echo "READ_AHEAD|DROP_NEW_LINE\n";
$fo = new SplFileObject($p);
$fo->setFlags(SplFileObject::DROP_NEW_LINE | SplFileObject::READ_AHEAD);
foreach ($fo as $k => $line) {
    echo "k=$k type=", gettype($line), ' val=', var_export($line, true), "\n";
}

echo "no flags trailing\n";
$fo = new SplFileObject($p);
$fo->setFlags(0);
foreach ($fo as $k => $line) {
    echo "k=$k type=", gettype($line), ' val=', var_export($line, true), "\n";
}

@unlink($p);

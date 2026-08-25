<?php
/**
 * #34776 — READ_CSV|SKIP_EMPTY must not yield trailing [null] after trailing newline.
 */
$tmp = sys_get_temp_dir().'/phpc_rcsv_skip_'.getmypid().'.csv';
file_put_contents($tmp, "a,b\nc,d\n");

$f = new SplFileObject($tmp);
$f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
foreach ($f as $r) {
    var_export($r);
    echo "\n";
}

echo "---\n";

// Control: without SKIP_EMPTY both sides yield trailing [null].
$f2 = new SplFileObject($tmp);
$f2->setFlags(SplFileObject::READ_CSV);
foreach ($f2 as $r) {
    var_export($r);
    echo "\n";
}

@unlink($tmp);

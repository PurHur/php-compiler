<?php
$tmp = sys_get_temp_dir() . '/phpc_issue_19663_' . getmypid() . '.csv';
file_put_contents($tmp, "a,b\n1,2\n");
$o = new SplFileObject($tmp);
$o->setFlags(SplFileObject::READ_CSV);
foreach ($o as $i => $row) {
    echo "i=$i type=", gettype($row), ' ';
    var_export($row);
    echo "\n";
}
unlink($tmp);

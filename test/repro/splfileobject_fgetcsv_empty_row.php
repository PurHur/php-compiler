<?php
$p = sys_get_temp_dir() . "/sfo_csv_" . getmypid() . ".csv";
file_put_contents($p, "x\n");
$fo = new SplFileObject($p);
for ($i = 0; $i < 3; $i++) {
    $row = $fo->fgetcsv();
    echo "i=$i ", var_export($row, true), "\n";
}
unlink($p);

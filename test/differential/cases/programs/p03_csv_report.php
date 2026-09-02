<?php
// #36221 program: CSV parse/report via php://temp (no host FS)
$csv = "name,dept,amount\n"
    . "ada,eng,12.50\n"
    . "grace,eng,8.00\n"
    . "alan,ops,3.25\n"
    . "ada,ops,1.75\n"
    . "grace,eng,2.00\n";
$fh = fopen('php://temp', 'r+');
fwrite($fh, $csv);
rewind($fh);
$header = fgetcsv($fh);
$byDept = [];
$rows = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 3) {
        continue;
    }
    $dept = $row[1];
    $amt = (float) $row[2];
    if (!isset($byDept[$dept])) {
        $byDept[$dept] = ['n' => 0, 'sum' => 0.0];
    }
    $byDept[$dept]['n']++;
    $byDept[$dept]['sum'] += $amt;
    $rows++;
}
fclose($fh);
ksort($byDept);
$lines = ['header=' . implode('|', $header), 'rows=' . $rows];
foreach ($byDept as $dept => $agg) {
    $lines[] = sprintf('%s:n=%d:sum=%.2f', $dept, $agg['n'], $agg['sum']);
}
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";

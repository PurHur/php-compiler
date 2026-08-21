<?php
// #33397 — READ_CSV iterator current/foreach must yield CSV field arrays (not raw lines).
// Avoid json_encode for null fields: thin AOT json_encode([null]) prints [] (separate defect).
function dump_row($row): string
{
    if (!\is_array($row)) {
        return \gettype($row).':'.(string) $row;
    }
    $parts = [];
    foreach ($row as $v) {
        $parts[] = null === $v ? 'NULL' : '"'.$v.'"';
    }

    return '['.\implode(',', $parts).']';
}

$tmp = \sys_get_temp_dir().'/phpc_rcsv_'.\getmypid().'.csv';
\file_put_contents($tmp, "1,2\n3,4\n");
$f = new SplFileObject($tmp);
$f->setFlags(SplFileObject::READ_CSV);
$out = [];
foreach ($f as $i => $row) {
    $out[] = $i.':'.dump_row($row);
}
echo \implode('|', $out), "\n";
$f2 = new SplFileObject($tmp);
$f2->setFlags(SplFileObject::READ_CSV);
$f2->rewind();
echo 'cur:', dump_row($f2->current()), "\n";
$f2->next();
echo 'cur2:', dump_row($f2->current()), "\n";
echo 'fgets:', \json_encode((new SplFileObject($tmp))->fgets()), "\n";
\unlink($tmp);

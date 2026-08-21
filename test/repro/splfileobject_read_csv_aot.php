<?php
// #33397 — READ_CSV iterator yields CSV field arrays (not raw lines).
// Indexed walk avoids NestedJIT foreach-on-row; is_null for EOF null field (#27069).
function dump_row($row): string
{
    if (!\is_array($row)) {
        return \gettype($row).':'.(string) $row;
    }
    $n = \count($row);
    $parts = [];
    for ($j = 0; $j < $n; ++$j) {
        $v = $row[$j];
        $parts[] = null === $v ? 'NULL' : '"'.$v.'"';
    }

    return '['.\implode(',', $parts).']';
}

$tmp = \sys_get_temp_dir().'/phpc_rcsv_'.\uniqid('', true).'.csv';
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
$f3 = new SplFileObject($tmp);
echo 'fgets:', \json_encode($f3->fgets()), "\n";
unset($f, $f2, $f3, $out, $row, $i);
\unlink($tmp);

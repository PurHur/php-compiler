<?php
// AOT: SplFileObject::READ_CSV — current/foreach yield CSV arrays (#33397).
// Avoid json_encode on setNullAt nulls (thin-AOT prints "[]"; lit [null] is fine).
$path = sys_get_temp_dir() . '/phpc_rcsv_' . getmypid() . '.csv';
file_put_contents($path, "1,2\n3,4\n");

$f = new SplFileObject($path);
$f->setFlags(SplFileObject::READ_CSV);
$parts = [];
foreach ($f as $i => $row) {
    if (!\is_array($row)) {
        $parts[] = $i . ':ERR';
        continue;
    }
    $n = \count($row);
    if (1 === $n && \array_key_exists(0, $row) && null === $row[0]) {
        $parts[] = $i . ':[null]';
        continue;
    }
    $parts[] = $i . ':' . \json_encode($row);
}
echo implode('|', $parts), "\n";

$f2 = new SplFileObject($path);
$f2->setFlags(SplFileObject::READ_CSV);
$f2->rewind();
$cur = $f2->current();
echo 'current=', \is_array($cur) ? \json_encode($cur) : 'ERR', "\n";

$f3 = new SplFileObject($path);
$f3->setFlags(SplFileObject::READ_CSV);
echo 'fgets=', \json_encode($f3->fgets()), "\n";
@unlink($path);

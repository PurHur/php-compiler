<?php

declare(strict_types=1);

$o = new SplFileObject('php://memory', 'w+');
$o->fwrite("a,b\n");
$o->rewind();
$row = $o->fgetcsv();
if ($row !== ['a', 'b']) {
    echo 'fail fgetcsv: '.var_export($row, true)."\n";
    exit(1);
}

$written = $o->fputcsv(['x', 'y']);
if (!\is_int($written) || $written < 1) {
    echo 'fail fputcsv bytes: '.var_export($written, true)."\n";
    exit(1);
}

$o->rewind();
$row2 = $o->fgetcsv();
if ($row2 !== ['a', 'b']) {
    echo 'fail first row after fputcsv: '.var_export($row2, true)."\n";
    exit(1);
}
$row3 = $o->fgetcsv();
if ($row3 !== ['x', 'y']) {
    echo 'fail second row: '.var_export($row3, true)."\n";
    exit(1);
}

echo "ok\n";

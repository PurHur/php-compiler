<?php

declare(strict_types=1);

$row = str_getcsv('a""b,c', ',', '"', '\\');
if ($row !== ['a""b', 'c']) {
    fwrite(STDERR, 'fail: str_getcsv unquoted enclosure literal: '.var_export($row, true)."\n");
    exit(1);
}

$handle = fopen('php://memory', 'r+');
if (false === $handle) {
    fwrite(STDERR, "fail: fopen\n");
    exit(1);
}
fwrite($handle, "a\"\"b,c\n");
rewind($handle);
$frow = fgetcsv($handle, separator: ',', enclosure: '"', escape: '\\');
fclose($handle);
if ($frow !== ['a""b', 'c']) {
    fwrite(STDERR, 'fail: fgetcsv unquoted enclosure literal: '.var_export($frow, true)."\n");
    exit(1);
}

echo "ok\n";

<?php
declare(strict_types=1);

file_put_contents('/tmp/splfile_gcl_fseek.txt', "line0\nline1\nline2\n");
$fo = new SplFileObject('/tmp/splfile_gcl_fseek.txt');
$fo->fseek(0);
$current = $fo->current();
$gcl = $fo->getCurrentLine();
if (!is_string($current) || !is_string($gcl)) {
    echo "not_string\n";
    exit(1);
}
if ($current === $gcl) {
    echo "current_equals_gcl\n";
    exit(1);
}
if (!str_starts_with($current, 'line0') || !str_starts_with($gcl, 'line1')) {
    echo 'bad current='.var_export($current, true).' gcl='.var_export($gcl, true)."\n";
    exit(1);
}
echo "OK\n";

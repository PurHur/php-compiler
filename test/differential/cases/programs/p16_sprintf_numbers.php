<?php
// #36221 program: sprintf / number_format / printf-style formatting
$vals = [0, 1, -1, 12.3456, 1234567.891, 0.1, 99.999];
$lines = [];
foreach ($vals as $v) {
    $lines[] = sprintf('raw=%s|%0.2f|%+d|%e', (string) $v, (float) $v, (int) $v, (float) $v);
}
$lines[] = 'nf=' . number_format(1234567.891, 2, '.', ',');
$lines[] = 'nf2=' . number_format(1234.5, 0);
$lines[] = 'money=' . sprintf('$%0.2f', 19.99);
$lines[] = 'pad=' . sprintf('%05d', 42);
$lines[] = 'hex=' . sprintf('%x', 255);
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";

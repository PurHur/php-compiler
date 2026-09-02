<?php
// #36221 program: repeated concat / substr / str_replace pipeline
$parts = [];
for ($i = 0; $i < 20; $i++) {
    $parts[] = 'x' . $i;
}
$s = implode('-', $parts);
$s2 = str_replace('x1', 'X1', $s);
$s3 = substr($s2, 0, 30) . '...' . substr($s2, -10);
$s4 = '';
for ($i = 0; $i < 5; $i++) {
    $s4 .= '[' . $i . ']';
}
$s5 = strrev($s4);
$out = "s_len=" . strlen($s) . "\ns3=$s3\ns5=$s5\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";

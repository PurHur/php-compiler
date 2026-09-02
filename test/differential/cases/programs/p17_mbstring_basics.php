<?php
// #36221 program: mbstring length/case/substr vs byte ops
$s = "ÄÖÜ café";
$lines = [
    'strlen=' . strlen($s),
    'mb_strlen=' . mb_strlen($s, 'UTF-8'),
    'upper=' . mb_strtoupper($s, 'UTF-8'),
    'lower=' . mb_strtolower($s, 'UTF-8'),
    'sub=' . mb_substr($s, 0, 3, 'UTF-8'),
    'pos=' . (string) mb_strpos($s, 'é', 0, 'UTF-8'),
    'bytes=' . bin2hex(substr($s, 0, 4)),
];
$out = implode("\n", $lines) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";

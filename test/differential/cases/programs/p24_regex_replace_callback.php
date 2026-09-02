<?php
// #36221 program: preg_replace_callback + named groups
$text = 'user:ada id:42 user:grace id:7 score:100';
$n = 0;
$outText = preg_replace_callback(
    '/(?P<key>user|id|score):(?P<val>\\w+)/',
    static function (array $m) use (&$n): string {
        $n++;
        return strtoupper($m['key']) . '=' . $m['val'];
    },
    $text
);
$parts = preg_split('/\\s+/', (string) $outText);
sort($parts);
$out = 'n=' . $n . '|sorted=' . implode(',', $parts) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";

<?php
declare(strict_types=1);

// #23200 — short printable samples are octet-stream until length >= 3 (libmagic).
$f = finfo_open(FILEINFO_MIME_TYPE);
echo finfo_buffer($f, 'x'), "\n";
echo finfo_buffer($f, 'aa'), "\n";
echo finfo_buffer($f, 'aaa'), "\n";
$dir = sys_get_temp_dir();
$short = $dir . '/phpc_issue_23200_short.bin';
$long = $dir . '/phpc_issue_23200_long.bin';
file_put_contents($short, 'x');
echo mime_content_type($short), "\n";
file_put_contents($long, 'abc');
echo mime_content_type($long), "\n";
@unlink($short);
@unlink($long);
finfo_close($f);

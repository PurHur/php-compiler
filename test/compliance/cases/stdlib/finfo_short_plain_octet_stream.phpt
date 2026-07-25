--TEST--
stdlib finfo/mime short ASCII (<3) is application/octet-stream (#23200)
--FILE--
<?php
declare(strict_types=1);
$f = finfo_open(FILEINFO_MIME_TYPE);
echo finfo_buffer($f, 'x'), "\n";
echo finfo_buffer($f, 'aa'), "\n";
echo finfo_buffer($f, 'aaa'), "\n";
$dir = sys_get_temp_dir();
$short = $dir . '/phpc_finfo_short_plain_1.bin';
$long = $dir . '/phpc_finfo_short_plain_3.bin';
file_put_contents($short, 'x');
echo mime_content_type($short), "\n";
file_put_contents($long, 'hello');
echo mime_content_type($long), "\n";
@unlink($short);
@unlink($long);
finfo_close($f);
?>
--EXPECT--
application/octet-stream
application/octet-stream
text/plain
application/octet-stream
text/plain

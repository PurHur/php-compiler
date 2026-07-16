<?php
declare(strict_types=1);

$fi = finfo_open(FILEINFO_MIME_TYPE);
$bin = "\x89PNG\r\n\x1a\n";
echo finfo_buffer($fi, $bin), "\n";
echo (new finfo(FILEINFO_MIME_TYPE))->buffer($bin), "\n";
$ihdr = pack('N', 13).'IHDR'.pack('NN', 1, 1).chr(8).chr(2).chr(0).chr(0).chr(0).pack('N', 0);
echo finfo_buffer($fi, $bin.$ihdr), "\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo finfo_buffer($fi, $png), "\n";

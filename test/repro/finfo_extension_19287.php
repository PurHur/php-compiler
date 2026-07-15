<?php
declare(strict_types=1);
$f = finfo_open(FILEINFO_EXTENSION);
echo finfo_buffer($f, "\xff\xd8\xff\xe0\x00\x10JFIF"), "\n";
echo finfo_buffer($f, "\x89PNG\r\n\x1a\n"), "\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo finfo_buffer($f, $png), "\n";
echo finfo_buffer($f, "%PDF-1.4\n"), "\n";
echo finfo_buffer($f, "GIF89a"), "\n";

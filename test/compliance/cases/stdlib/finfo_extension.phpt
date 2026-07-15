--TEST--
stdlib finfo FILEINFO_EXTENSION fixture lists (#19287, ext/fileinfo)
--FILE--
<?php
declare(strict_types=1);

$f = finfo_open(FILEINFO_EXTENSION);

echo finfo_buffer($f, "\xff\xd8\xff\xe0\x00\x10JFIF"), "\n";
echo finfo_buffer($f, "GIF89a"), "\n";
echo finfo_buffer($f, "%PDF-1.4\n"), "\n";
echo finfo_buffer($f, ''), "\n";
echo finfo_buffer($f, "<?php echo 1;"), "\n";
echo finfo_buffer($f, "\x89PNG\r\n\x1a\n"), "\n";

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo finfo_buffer($f, $png), "\n";

echo finfo_buffer($f, "RIFF....WAVE"), "\n";
echo finfo_buffer($f, "II*\x00"), "\n";

$none = finfo_open(FILEINFO_NONE);
echo (0 === strpos((string) finfo_buffer($none, $png), 'PNG image data') ? 'none_ok' : 'none_bad'), "\n";

finfo_close($f);
finfo_close($none);
--EXPECT--
jpeg/jpg/jpe/jfif
gif
pdf
???
???
???
png
wav/wave
tif,tiff
none_ok

--TEST--
stdlib finfo FILEINFO_NONE/RAW human descriptions (#19247)
--FILE--
<?php
declare(strict_types=1);

$f = finfo_open(FILEINFO_NONE);

echo finfo_buffer($f, "<?php\n"), "\n";
echo finfo_buffer($f, ''), "\n";

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo finfo_buffer($f, $png), "\n";
echo finfo_buffer($f, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 8)), "\n";

$elf = "\x7fELF\x02\x01\x01\x00" . str_repeat("\0", 8);
$elf .= pack('v', 3);  // ET_DYN
$elf .= pack('v', 62); // EM_X86_64
$elf .= pack('V', 1);
$elf .= str_repeat("\0", 40);
echo finfo_buffer($f, $elf), "\n";

echo finfo_buffer($f, "%PDF-1.4\n"), "\n";
echo finfo_buffer($f, "<html><body></body></html>\n"), "\n";
echo finfo_buffer($f, "GIF89a" . str_repeat("\0", 20)), "\n";

$raw = finfo_open(FILEINFO_RAW);
echo finfo_buffer($raw, "<?php\n"), "\n";

if (is_readable('/bin/ls')) {
    $ls = finfo_file($f, '/bin/ls');
    echo (0 === strpos((string) $ls, 'ELF 64-bit LSB') ? 'binls_elf_ok' : 'binls_bad'), "\n";
} else {
    echo "binls_skip\n";
}

finfo_close($f);
finfo_close($raw);
--EXPECT--
PHP script, ASCII text
empty
PNG image data, 1 x 1, 8-bit/color RGBA, non-interlaced
data
ELF 64-bit LSB shared object, x86-64, version 1 (SYSV)
PDF document, version 1.4
HTML document, ASCII text
GIF image data, version 89a,
PHP script, ASCII text
binls_elf_ok

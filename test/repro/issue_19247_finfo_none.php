<?php
declare(strict_types=1);

$f = finfo_open(FILEINFO_NONE);
echo finfo_buffer($f, "<?php\n"), "\n";
echo finfo_buffer($f, ''), "\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
echo finfo_buffer($f, $png), "\n";
$elf = "\x7fELF\x02\x01\x01\x00" . str_repeat("\0", 8);
$elf .= pack('v', 3);
$elf .= pack('v', 62);
$elf .= pack('V', 1);
$elf .= str_repeat("\0", 40);
echo finfo_buffer($f, $elf), "\n";
if (is_readable('/bin/ls')) {
    echo finfo_file($f, '/bin/ls'), "\n";
}

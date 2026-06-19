<?php
declare(strict_types=1);

$loaded = php_ini_loaded_file();
$scanned = php_ini_scanned_files();

echo 'loaded_type:', \gettype($loaded), "\n";
echo 'scanned_type:', \gettype($scanned), "\n";

if (\is_string($loaded)) {
    echo 'loaded_nonempty:', ($loaded !== '' ? '1' : '0'), "\n";
}
if (\is_string($scanned)) {
    echo 'scanned_has_ini:', (str_contains($scanned, '.ini') ? '1' : '0'), "\n";
}

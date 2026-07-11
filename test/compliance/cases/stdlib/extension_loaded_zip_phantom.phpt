--TEST--
stdlib extension_loaded('zip') false until libzip implemented (#11676, ext/zip/php_zip.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('zip'), "\n";
echo 'in_list=', (int) in_array('zip', get_loaded_extensions(), true), "\n";
echo 'class=', (int) class_exists('ZipArchive'), "\n";
--EXPECT--
loaded=0
in_list=0
class=0

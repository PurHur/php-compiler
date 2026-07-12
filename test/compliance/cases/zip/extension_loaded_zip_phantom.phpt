--TEST--
zip extension_loaded('zip') withheld on reference profile (#18137, ext/zip/php_zip.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('zip'), "\n";
echo 'in_list=', (int) in_array('zip', get_loaded_extensions(), true), "\n";
echo 'class=', (int) class_exists('ZipArchive', false), "\n";
echo 'zip_open=', (int) function_exists('zip_open'), "\n";
--EXPECT--
loaded=0
in_list=0
class=0
zip_open=0

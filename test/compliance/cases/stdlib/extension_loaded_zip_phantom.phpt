--TEST--
stdlib extension_loaded('zip') true with pure-PHP ZipArchive (#3337, ext/zip/php_zip.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('zip'), "\n";
echo 'in_list=', (int) in_array('zip', get_loaded_extensions(), true), "\n";
echo 'class=', (int) class_exists('ZipArchive'), "\n";
--EXPECT--
loaded=1
in_list=1
class=1

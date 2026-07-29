--TEST--
stdlib extension_loaded('zip') withheld under PROFILE=8.4 without host (#25010)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('zip'), "\n";
echo 'class=', (int) class_exists('ZipArchive', false), "\n";
echo 'zip_open=', (int) function_exists('zip_open'), "\n";
--EXPECT--
loaded=0
class=0
zip_open=0

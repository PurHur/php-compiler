--TEST--
exif EXIF_USE_MBSTRING constant registered like Zend (#24064, ext/exif/exif.c)
--FILE--
<?php
echo defined('EXIF_USE_MBSTRING') ? "defined_ok\n" : "defined_fail\n";
echo EXIF_USE_MBSTRING === 1 ? "value_one\n" : ("value_" . EXIF_USE_MBSTRING . "\n");
echo extension_loaded('mbstring') ? "mbstring_loaded\n" : "mbstring_missing\n";
?>
--EXPECT--
defined_ok
value_one
mbstring_loaded

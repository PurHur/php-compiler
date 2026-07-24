--TEST--
IMAGETYPE_HEIF present on PROFILE=8.5 with Zend value 20 (#22787, ext/standard/image.c)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo 'HEIF=', defined('IMAGETYPE_HEIF') ? '1' : '0', "\n";
echo 'AVIF=', defined('IMAGETYPE_AVIF') ? '1' : '0', "\n";
echo 'HEIF_val=', IMAGETYPE_HEIF, "\n";
echo 'COUNT=', IMAGETYPE_COUNT, "\n";
echo 'AVIF_val=', IMAGETYPE_AVIF, "\n";
?>
--EXPECT--
HEIF=1
AVIF=1
HEIF_val=20
COUNT=21
AVIF_val=19

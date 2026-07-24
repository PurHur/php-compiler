--TEST--
IMAGETYPE_HEIF withheld on PROFILE=8.2; AVIF + COUNT match Zend (#22787, ext/standard/image.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo 'HEIF=', defined('IMAGETYPE_HEIF') ? '1' : '0', "\n";
echo 'AVIF=', defined('IMAGETYPE_AVIF') ? '1' : '0', "\n";
echo 'COUNT=', IMAGETYPE_COUNT, "\n";
echo 'AVIF_val=', IMAGETYPE_AVIF, "\n";
try {
    $v = IMAGETYPE_HEIF;
    echo 'use=', $v, "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
HEIF=0
AVIF=1
COUNT=20
AVIF_val=19
Error

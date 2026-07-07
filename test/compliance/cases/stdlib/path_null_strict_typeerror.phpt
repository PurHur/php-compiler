--TEST--
stdlib getimagesize/md5_file/sha1_file null under strict_types throws TypeError (#17060, ext/standard/image.c, md5.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['getimagesize', 'md5_file', 'sha1_file'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
getimagesize: getimagesize(): Argument #1 ($filename) must be of type string, null given
md5_file: md5_file(): Argument #1 ($filename) must be of type string, null given
sha1_file: sha1_file(): Argument #1 ($filename) must be of type string, null given

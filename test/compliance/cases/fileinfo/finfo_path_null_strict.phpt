--TEST--
stdlib finfo_file/buffer(null) TypeError under strict_types (#30259, ext/fileinfo/fileinfo.c)
--FILE--
<?php
declare(strict_types=1);
$fi = finfo_open(FILEINFO_MIME_TYPE);
try {
    finfo_file($fi, null);
    echo "fail_file\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    finfo_buffer($fi, null);
    echo "fail_buf\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $fi->file(null);
    echo "fail_mfile\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $fi->buffer(null);
    echo "fail_mbuf\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
TypeError:finfo_file(): Argument #2 ($filename) must be of type string, null given
TypeError:finfo_buffer(): Argument #2 ($string) must be of type string, null given
TypeError:finfo::file(): Argument #1 ($filename) must be of type string, null given
TypeError:finfo::buffer(): Argument #1 ($string) must be of type string, null given

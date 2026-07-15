--TEST--
stdlib Z_PARAM_PATH null — ValueError Path cannot be empty (#19146, ext/standard/md5.c, file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['md5_file', 'sha1_file', 'file_get_contents'] as $fn) {
    try {
        $fn(null);
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5_file:Path cannot be empty
sha1_file:Path cannot be empty
file_get_contents:Path cannot be empty

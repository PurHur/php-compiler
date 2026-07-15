--TEST--
stdlib fopen/copy/readfile/file null path — ValueError Path cannot be empty (#19162, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['md5_file', 'sha1_file', 'file_get_contents', 'fopen', 'copy', 'readfile', 'file'] as $label) {
    try {
        match ($label) {
            'fopen' => fopen(null, 'r'),
            'copy' => copy(null, 'x'),
            'readfile' => readfile(null),
            'file' => file(null),
            default => $label(null),
        };
        echo $label, ": miss\n";
    } catch (ValueError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5_file:Path cannot be empty
sha1_file:Path cannot be empty
file_get_contents:Path cannot be empty
fopen:Path cannot be empty
copy:Path cannot be empty
readfile:Path cannot be empty
file:Path cannot be empty

--TEST--
stdlib fopen/copy/readfile/file null path JIT — empty-path ValueError on 8.4 (#21235, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
    } catch (TypeError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $label, ':VALUEERROR:', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5_file:VALUEERROR:Path cannot be empty
sha1_file:VALUEERROR:Path cannot be empty
file_get_contents:VALUEERROR:Path cannot be empty
fopen:VALUEERROR:Path cannot be empty
copy:VALUEERROR:Path cannot be empty
readfile:VALUEERROR:Path cannot be empty
file:VALUEERROR:Path cannot be empty

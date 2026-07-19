--TEST--
stdlib fopen/copy/readfile/file null path JIT — TypeError on 8.4 forward profile (#21076, ext/standard/file.c)
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
md5_file:md5_file(): Argument #1 ($filename) must be of type string, null given
sha1_file:sha1_file(): Argument #1 ($filename) must be of type string, null given
file_get_contents:file_get_contents(): Argument #1 ($filename) must be of type string, null given
fopen:fopen(): Argument #1 ($filename) must be of type string, null given
copy:copy(): Argument #1 ($from) must be of type string, null given
readfile:readfile(): Argument #1 ($filename) must be of type string, null given
file:file(): Argument #1 ($filename) must be of type string, null given

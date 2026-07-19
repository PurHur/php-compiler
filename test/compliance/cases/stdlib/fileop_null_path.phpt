--TEST--
stdlib fopen/copy/readfile/file null path — TypeError on 8.4 forward profile (#21076, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$null = null;
foreach ([
    'fopen' => [$null, 'r'],
    'copy' => [$null, 'x'],
    'readfile' => [$null],
    'file' => [$null],
] as $label => $args) {
    try {
        $label(...$args);
        echo $label, ": miss\n";
    } catch (TypeError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $label, ':VALUEERROR:', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
fopen:fopen(): Argument #1 ($filename) must be of type string, null given
copy:copy(): Argument #1 ($from) must be of type string, null given
readfile:readfile(): Argument #1 ($filename) must be of type string, null given
file:file(): Argument #1 ($filename) must be of type string, null given

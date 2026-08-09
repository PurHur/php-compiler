--TEST--
stdlib fopen/copy/readfile/file null path — empty-path ValueError on 8.4 (#21235, ext/standard/file.c)
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
fopen:VALUEERROR:Path must not be empty
copy:VALUEERROR:Path must not be empty
readfile:VALUEERROR:Path must not be empty
file:VALUEERROR:Path must not be empty

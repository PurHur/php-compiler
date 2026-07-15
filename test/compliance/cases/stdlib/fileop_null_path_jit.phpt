--TEST--
stdlib fopen/copy/readfile/file null path JIT — ValueError Path cannot be empty (#19162, ext/standard/file.c)
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
    } catch (ValueError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
fopen:Path cannot be empty
copy:Path cannot be empty
readfile:Path cannot be empty
file:Path cannot be empty

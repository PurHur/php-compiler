--TEST--
stdlib empty path ValueError — Path cannot be empty (#30457, ext/standard/file.c)
--FILE--
<?php
foreach ([
    'readfile' => static fn () => readfile(''),
    'highlight_file' => static fn () => highlight_file(''),
    'file' => static fn () => file(''),
    'fopen' => static fn () => fopen('', 'r'),
    'copy' => static fn () => copy('', '/tmp/x'),
] as $fn => $call) {
    try {
        $call();
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
try {
    parse_ini_file('');
    echo "parse_ini_file: miss\n";
} catch (ValueError $e) {
    echo 'parse_ini_file:', $e->getMessage(), "\n";
}
?>
--EXPECT--
readfile:Path cannot be empty
highlight_file:Path cannot be empty
file:Path cannot be empty
fopen:Path cannot be empty
copy:Path cannot be empty
parse_ini_file:parse_ini_file(): Argument #1 ($filename) cannot be empty

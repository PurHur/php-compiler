--TEST--
stdlib empty path ValueError — Path cannot be empty (#29268, php-src fopen_wrappers.c)
--FILE--
<?php
foreach ([
    'fopen' => static fn () => fopen('', 'r'),
    'file_get_contents' => static fn () => file_get_contents(''),
    'hash_file' => static fn () => hash_file('sha256', ''),
] as $fn => $call) {
    try {
        $call();
        echo $fn, ": miss\n";
    } catch (ValueError $e) {
        echo $fn, ':', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
fopen:Path cannot be empty
file_get_contents:Path cannot be empty
hash_file:Path cannot be empty

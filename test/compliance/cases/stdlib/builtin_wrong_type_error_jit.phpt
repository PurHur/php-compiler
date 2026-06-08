--TEST--
stdlib builtins JIT — TypeError for wrong parameter types (#4178)
--FILE--
<?php
$h = fopen('/etc/hostname', 'r');
try {
    fread($h, []);
} catch (TypeError $e) {
    echo 'fread: ', get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($h);

try {
    substr('hello', []);
} catch (TypeError $e) {
    echo 'substr: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    chr([]);
} catch (TypeError $e) {
    echo 'chr: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
fread: TypeError: fread(): Argument #2 ($length) must be of type int, array given
substr: TypeError: substr(): Argument #2 ($offset) must be of type int, array given
chr: TypeError: chr(): Argument #1 ($codepoint) must be of type int, array given

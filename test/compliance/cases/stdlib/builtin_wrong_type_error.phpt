--TEST--
stdlib builtins — TypeError for wrong parameter types (#4178, Zend/zend_API.c)
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
    $w = fopen('php://memory', 'w+');
    fwrite($w, []);
    fclose($w);
} catch (TypeError $e) {
    echo 'fwrite: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    substr('hello', []);
} catch (TypeError $e) {
    echo 'substr: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    putenv([]);
} catch (TypeError $e) {
    echo 'putenv: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    chr([]);
} catch (TypeError $e) {
    echo 'chr: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
fread: TypeError: fread(): Argument #2 ($length) must be of type int, array given
fwrite: TypeError: fwrite(): Argument #2 ($data) must be of type string, array given
substr: TypeError: substr(): Argument #2 ($offset) must be of type int, array given
putenv: TypeError: putenv(): Argument #1 ($assignment) must be of type string, array given
chr: TypeError: chr(): Argument #1 ($codepoint) must be of type int, array given

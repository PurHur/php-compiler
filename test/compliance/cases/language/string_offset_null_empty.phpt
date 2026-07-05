--TEST--
Language: null/empty string offset assignment throws catchable Error (zend_execute.c, #16596)
--FILE--
<?php
$msg = 'Cannot assign an empty string to a string offset';
$s = 'abc';
try {
    $s[1] = null;
} catch (\Error $e) {
    echo 'null:', $e->getMessage(), "\n";
}
$s = 'abc';
try {
    $s[1] = '';
} catch (\Error $e) {
    echo 'empty:', $e->getMessage(), "\n";
}
$s = 'abc';
$s[1] = 'x';
echo $s, "\n";
?>
--EXPECT--
null:Cannot assign an empty string to a string offset
empty:Cannot assign an empty string to a string offset
axc

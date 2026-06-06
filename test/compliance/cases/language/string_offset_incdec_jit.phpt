--TEST--
Language: ++/-- on string offsets throws Error under JIT (zend_operators.c, #6798)
--FILE--
<?php
$s = 'ab';
try {
    $s[0]++;
} catch (\Error $e) {
    echo 'post-inc:', $e->getMessage(), "\n";
}
try {
    ++$s[1];
} catch (\Error $e) {
    echo 'pre-inc:', $e->getMessage(), "\n";
}
try {
    $s[0]--;
} catch (\Error $e) {
    echo 'post-dec:', $e->getMessage(), "\n";
}
try {
    --$s[0];
} catch (\Error $e) {
    echo 'pre-dec:', $e->getMessage(), "\n";
}
?>
--EXPECT--
post-inc:Cannot increment/decrement string offsets
pre-inc:Cannot increment/decrement string offsets
post-dec:Cannot increment/decrement string offsets
pre-dec:Cannot increment/decrement string offsets

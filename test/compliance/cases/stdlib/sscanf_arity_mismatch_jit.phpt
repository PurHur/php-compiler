--TEST--
JIT: sscanf() — out-variable count vs format specifiers ValueError (#4064)
--FILE--
<?php
$a = 0;
try {
    sscanf('42 foo', '%d %s', $a);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'arity: ', $e->getMessage(), "\n";
}

$b = 0;
$c = 0;
try {
    sscanf('42', '%d', $b, $c);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'extra: ', $e->getMessage(), "\n";
}
--EXPECT--
arity: Different numbers of variable names and field specifiers
extra: Variable is not assigned by any conversion specifiers

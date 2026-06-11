<?php
// Compile-only (#4064): sscanf() arity ValueError lowering for AOT lint.
$a = 0;
try {
    sscanf('42 foo', '%d %s', $a);
} catch (ValueError $e) {
    echo 'arity: ', $e->getMessage(), "\n";
}

$b = 0;
$c = 0;
try {
    sscanf('42', '%d', $b, $c);
} catch (ValueError $e) {
    echo 'extra: ', $e->getMessage(), "\n";
}

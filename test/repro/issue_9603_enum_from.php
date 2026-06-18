<?php
enum E: string {
    case A = 'a';
}

var_dump(E::tryFrom('b')); // Zend: NULL
var_dump(E::tryFrom('a')); // Zend: enum(E::A)

try {
    E::from('a');
    echo "from ok\n";
} catch (ValueError $e) {
    echo 'from fail: ', $e->getMessage(), "\n";
}

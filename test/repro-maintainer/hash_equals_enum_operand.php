<?php

enum E: string
{
    case A = 'x';
}

try {
    var_export(hash_equals(E::A, 'x'));
    echo "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

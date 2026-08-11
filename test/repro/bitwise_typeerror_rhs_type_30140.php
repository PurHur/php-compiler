<?php
// #30140 — bitwise TypeError must include both operand types and operator (Zend parity)

foreach ([
    'and' => fn() => "a" & 1,
    'or'  => fn() => "a" | 1,
    'xor' => fn() => "a" ^ 1,
    'obj' => fn() => (new stdClass) & 1,
] as $n => $fn) {
    try {
        $fn();
    } catch (TypeError $e) {
        echo $n, ':', $e->getMessage(), PHP_EOL;
    }
}

// string↔string bitwise must still work (no TypeError)
echo 'str:', ("a" ^ "b"), PHP_EOL;

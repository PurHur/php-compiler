<?php
// Issue #24329 — UnhandledMatchError must format variable subjects (not always NULL).
foreach ([
    'int' => 3,
    'str' => 'secret-value',
    'null' => null,
    'true' => true,
    'false' => false,
    'arr' => [1],
] as $label => $v) {
    try {
        match ($v) {
            0 => 0,
            'nope' => 1,
        };
        echo "$label:no\n";
    } catch (UnhandledMatchError $e) {
        echo $label, ':', $e->getMessage(), "\n";
    }
}
try {
    match (false) {
        0 => 0,
    };
} catch (UnhandledMatchError $e) {
    echo 'litfalse:', $e->getMessage(), "\n";
}

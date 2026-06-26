<?php
/**
 * Repro: builtin type ::class constants (#11909).
 * Zend: each line prints the type name; final line prints ok.
 */
$checks = [
    int::class => 'int',
    float::class => 'float',
    bool::class => 'bool',
    string::class => 'string',
    object::class => 'object',
    iterable::class => 'iterable',
    void::class => 'void',
    never::class => 'never',
    mixed::class => 'mixed',
    null::class => 'null',
    false::class => 'false',
    true::class => 'true',
];
foreach ($checks as $actual => $expected) {
    if ($actual !== $expected) {
        echo "fail {$expected} got {$actual}\n";
        exit(1);
    }
}
echo "ok\n";

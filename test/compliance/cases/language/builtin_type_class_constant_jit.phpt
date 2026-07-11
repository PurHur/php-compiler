--TEST--
Language: builtin type ::class constants JIT (Zend/zend_compile.c, #11909)
--FILE--
<?php
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
--EXPECT--
ok

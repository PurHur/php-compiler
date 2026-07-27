<?php

// Issue #23679: var_dump/debug_zval_dump named args + Reflection names

$r = new ReflectionFunction('var_dump');
$names = array_map(fn($p) => $p->getName(), $r->getParameters());
assert($names === ['value', 'values'], 'var_dump params: ' . implode(',', $names));

$r2 = new ReflectionFunction('debug_zval_dump');
$names2 = array_map(fn($p) => $p->getName(), $r2->getParameters());
assert($names2 === ['value', 'values'], 'debug_zval_dump params: ' . implode(',', $names2));

ob_start();
var_dump(value: 42);
$out = trim(ob_get_clean());
assert($out === 'int(42)', 'var_dump(value:42) => ' . $out);

ob_start();
debug_zval_dump(value: 'hello');
$out2 = trim(ob_get_clean());
assert(str_contains($out2, 'string(5) "hello"'), 'debug_zval_dump(value:"hello") => ' . $out2);

echo "OK\n";

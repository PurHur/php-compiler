--TEST--
Stdlib: ReflectionEnumUnitCase::getValue() on unit enum throws Error (php_reflection.c, #7055)
--FILE--
<?php
enum Pure {
    case A;
}

$r = new ReflectionEnumUnitCase(Pure::class, 'A');
try {
    $r->getValue();
    echo "no throw\n";
} catch (Error $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

enum Backed: string {
    case A = 'x';
}

$rb = new ReflectionEnumUnitCase(Backed::class, 'A');
var_export($rb->getValue());
echo "\n";
--EXPECT--
Error: Cannot get value of a pure enum case
\Backed::A

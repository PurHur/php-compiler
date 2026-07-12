--TEST--
Stdlib: var_export() inline tryFrom() in concat — return flag honored (#18164, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

enum Color: string {
    case Red = 'red';
}

echo 'tryfrom:' . var_export(Color::tryFrom('green'), true) . "\n";

class C {
    public function f(): bool {
        return false;
    }
}

$c = new C();
echo 'x=' . var_export($c->f(), true) . "\n";
--EXPECT--
tryfrom:NULL
x=false

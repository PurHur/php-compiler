<?php

class C {
    public string $p = 'prop';

    public function __toString(): string {
        return 'obj';
    }
}

$c = new C();
var_export("{$c}");
echo "\n";
var_export("{$c->p}");
echo "\n";

$a = [1 => 'one', 2 => 'two'];
var_export("{$a[1]}");
echo "\n";

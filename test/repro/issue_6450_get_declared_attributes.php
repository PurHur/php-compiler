<?php

#[Attribute]
class MyAttr6450 {
    public function __construct(public string $x = '') {}
}

class UsesIt6450 {
    #[MyAttr6450('ok')]
    public int $p = 1;
}

var_export(function_exists('get_declared_attributes'));
echo "\n";
if (function_exists('get_declared_attributes')) {
    print_r(get_declared_attributes());
}

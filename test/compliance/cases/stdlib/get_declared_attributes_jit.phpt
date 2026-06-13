--TEST--
Stdlib: get_declared_attributes() lists user #[Attribute] classes (JIT, #6450)
--JIT--
--FILE--
<?php
#[Attribute]
class DeclaredAttr6450Jit {
    public function __construct(public string $x = '') {}
}

class UsesDeclaredAttr6450Jit {
    #[DeclaredAttr6450Jit('ok')]
    public int $p = 1;
}

echo function_exists('get_declared_attributes') ? '1' : '0';
$attrs = get_declared_attributes();
echo in_array('DeclaredAttr6450Jit', $attrs, true) ? '1' : '0';
echo in_array('UsesDeclaredAttr6450Jit', $attrs, true) ? '1' : '0';
echo in_array('Attribute', $attrs, true) ? '1' : '0';
echo "\n";
--EXPECT--
1100

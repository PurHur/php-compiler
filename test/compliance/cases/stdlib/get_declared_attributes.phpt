--TEST--
Stdlib: get_declared_attributes() lists user #[Attribute] classes (VM, #6450)
--FILE--
<?php
#[Attribute]
class DeclaredAttr6450 {
    public function __construct(public string $x = '') {}
}

class UsesDeclaredAttr6450 {
    #[DeclaredAttr6450('ok')]
    public int $p = 1;
}

echo function_exists('get_declared_attributes') ? '1' : '0';
$attrs = get_declared_attributes();
echo in_array('DeclaredAttr6450', $attrs, true) ? '1' : '0';
echo in_array('UsesDeclaredAttr6450', $attrs, true) ? '1' : '0';
echo in_array('Attribute', $attrs, true) ? '1' : '0';
echo "\n";
--EXPECT--
1100

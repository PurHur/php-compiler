--TEST--
Property hook typed set parameter compiles with nullable backing default (#6611, zend_compile.c)
--FILE--
<?php
class C {
    public string $x {
        get => $this->v ?? 'u';
        set(string $value) { $this->v = $value; }
    }
    private ?string $v = 'a';
}
$c = new C();
echo $c->x, "\n";
$c->x = 'b';
echo $c->x, "\n";
--EXPECT--
a
b

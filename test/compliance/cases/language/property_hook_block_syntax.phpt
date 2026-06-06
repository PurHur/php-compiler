--TEST--
Property hook block syntax get/set/unset { } — preprocess before curly rejector (#6650, Zend zend_compile.c)
--FILE--
<?php
class C {
    public int $x {
        get {
            return 42;
        }
    }
}
class S {
    public string $label {
        get => $this->label;
        set (string $value) {
            $this->label = strtoupper($value);
        }
    }
}
class U {
    public ?string $name {
        get => $this->name;
        set => $this->name = $value;
        unset {
            $this->name = null;
        }
    }
}
echo (new C())->x, "\n";
$s = new S();
$s->label = 'hi';
echo $s->label, "\n";
$u = new U();
$u->name = 'a';
echo $u->name, "\n";
unset($u->name);
echo ($u->name ?? 'null'), "\n";
--EXPECT--
42
HI
a
null

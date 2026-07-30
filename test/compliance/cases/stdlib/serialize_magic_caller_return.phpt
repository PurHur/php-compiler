--TEST--
stdlib serialize() via __serialize/__sleep must not clobber caller return (#25189)
--FILE--
<?php
function with_serialize_magic(): int {
    class A {
        public $x = 1;
        public function __serialize(): array { return ['x' => 1]; }
    }
    serialize(new A());
    return 99;
}
function with_sleep(): int {
    class B {
        public $x = 1;
        public function __sleep() { return ['x']; }
    }
    serialize(new B());
    return 99;
}
function with_plain(): int {
    class C {
        public $x = 1;
    }
    serialize(new C());
    return 99;
}
function with_serializable(): int {
    class D implements Serializable {
        public $x = 1;
        public function serialize() { return 'x'; }
        public function unserialize($data) { $this->x = 1; }
    }
    serialize(new D());
    return 99;
}
var_export(with_serialize_magic());
echo "\n";
var_export(with_sleep());
echo "\n";
var_export(with_plain());
echo "\n";
var_export(with_serializable());
echo "\n";
class E {
    public $prop = 42;
    public function __serialize(): array { return ['prop' => $this->prop]; }
    public function __unserialize(array $d): void { $this->prop = $d['prop']; }
}
$o = unserialize(serialize(new E()));
var_export($o->prop);
echo "\n";
--EXPECT--
99
99
99
99
42

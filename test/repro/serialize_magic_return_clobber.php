<?php
// #25189 — serialize() via __serialize/__sleep must not clobber caller return.
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
echo 'magic=';
var_export(with_serialize_magic());
echo "\n";
echo 'sleep=';
var_export(with_sleep());
echo "\n";
echo 'plain=';
var_export(with_plain());
echo "\n";
class E {
    public $prop = 42;
    public function __serialize(): array { return ['prop' => $this->prop]; }
    public function __unserialize(array $d): void { $this->prop = $d['prop']; }
}
$o = unserialize(serialize(new E()));
echo 'roundtrip=';
var_export($o->prop);
echo "\n";

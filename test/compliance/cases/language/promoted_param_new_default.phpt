--TEST--
Promoted constructor parameter defaults with new expressions (issue #6652)
--FILE--
<?php
class Marker {
    public function __construct(public string $tag = 'default') {}
}
class C {
    public function __construct(
        public Marker $m = new Marker('builtin'),
    ) {}
}
echo (new C())->m->tag, "\n";
echo (new C(new Marker('explicit')))->m->tag, "\n";
$a = new C();
$b = new C();
echo ($a->m !== $b->m) ? "1\n" : "0\n";
--EXPECT--
builtin
explicit
1

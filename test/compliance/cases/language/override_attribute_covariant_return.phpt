--TEST--
Language: valid #[\Override] with covariant object return (issue #6710)
--FILE--
<?php
class Base { public function foo(): object {} }
class Child extends Base {
    #[\Override]
    public function foo(): \stdClass { return new \stdClass(); }
}
echo "ok\n";
--EXPECT--
ok

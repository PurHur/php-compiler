--TEST--
Language: valid #[\Override] on parent method (issue #3211)
--FILE--
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
--EXPECT--
ok

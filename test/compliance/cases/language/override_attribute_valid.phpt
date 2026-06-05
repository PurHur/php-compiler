--TEST--
Language: valid #[\Override] on parent method (issue #3211, #6355)
--FILE--
<?php
class Base {
    public function foo(): string { return 'base'; }
}
class Child extends Base {
    #[\Override]
    public function foo(): string { return 'child'; }
}
echo (new Child())->foo() . "\n";
--EXPECT--
child

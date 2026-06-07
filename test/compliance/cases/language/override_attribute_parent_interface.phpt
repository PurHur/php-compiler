--TEST--
Language: valid #[\Override] on interface method via parent implements (issue #6710)
--FILE--
<?php
interface I { public function foo(): void; }
abstract class Base implements I {}
class Child extends Base {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
--EXPECT--
ok

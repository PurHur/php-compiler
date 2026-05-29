--TEST--
Language: anonymous class may extend abstract parent (issue #3385)
--FILE--
<?php
abstract class A {
    public function f(): int { return 1; }
}
$o = new class extends A {};
echo $o->f(), "\n";
--EXPECT--
1
